<?php

namespace App\Support;

use App\Events\OrderPaid;
use App\Exceptions\InsufficientStockException;
use App\Models\Card;
use App\Models\Order;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class OrderService
{
    /**
     * 创建订单:锁卡 → 建 order(pending)。
     * @param array $customer [contact, password?, extra?]
     * @throws InsufficientStockException
     */
    public function createOrder(int $productId, ?int $skuId, int $qty, array $customer): Order
    {
        $product = \App\Models\Product::with('skus')->findOrFail($productId);
        $unitPrice = $skuId
            ? ($product->skus->firstWhere('id', $skuId)?->price ?? $product->price)
            : $product->price;
        $amount = $unitPrice * $qty;

        return DB::transaction(function () use ($productId, $skuId, $qty, $customer, $product, $amount) {
            // 锁卡(FOR UPDATE 防并发超卖)
            $cards = Card::where('product_id', $productId)
                ->where('status', Card::STATUS_UNUSED)
                ->lockForUpdate()
                ->limit($qty)
                ->get();

            if ($cards->count() < $qty) {
                throw new InsufficientStockException("库存不足,需要 {$qty} 张,仅剩 {$cards->count()} 张");
            }

            $extra = array_merge(
                $skuId ? ['sku_id' => $skuId, 'sku_name' => $product->skus->firstWhere('id', $skuId)?->name] : [],
                ['control' => $customer['extra'] ?? []],
            );

            // 查询密码加密存
            if (! empty($customer['password'])) {
                $extra['query_password'] = Hash::make($customer['password']);
            }

            // 创建订单
            $order = Order::create([
                'order_no' => $this->generateOrderNo(),
                'merchant_id' => $product->merchant_id,
                'user_id' => null, // 游客
                'product_id' => $productId,
                'quantity' => $qty,
                'amount' => $amount,
                'status' => 'pending',
                'contact' => $customer['contact'] ?? null,
                'extra' => $extra,
            ]);

            // 锁定卡密
            $cards->each->update([
                'status' => Card::STATUS_LOCKED,
                'locked_at' => now(),
                'order_id' => $order->id,
            ]);

            return $order;
        });
    }

    /** 标记支付成功(状态机 pending→paid,fire OrderPaid) */
    public function markPaid(string $orderNo): Order
    {
        $order = DB::transaction(function () use ($orderNo) {
            $order = Order::where('order_no', $orderNo)->lockForUpdate()->firstOrFail();
            if ($order->status !== 'pending') {
                throw new \RuntimeException("订单状态异常: {$order->status},无法支付");
            }
            $order->update(['status' => 'paid', 'paid_at' => now()]);
            return $order;
        });

        event(new OrderPaid($order));

        return $order;
    }

    /** 超时关单(Scheduler 调),返回关闭数 */
    public function closeExpired(): int
    {
        $minutes = (int) (StorefrontConfig::get('order_close_minutes') ?? 15);
        $cutoff = now()->subMinutes($minutes);

        $expired = Order::where('status', 'pending')
            ->where('created_at', '<', $cutoff)
            ->get();

        $count = 0;
        foreach ($expired as $order) {
            DB::transaction(function () use ($order) {
                $order->update(['status' => 'closed', 'closed_at' => now()]);
                Card::where('order_id', $order->id)
                    ->where('status', Card::STATUS_LOCKED)
                    ->update([
                        'status' => Card::STATUS_UNUSED,
                        'locked_at' => null,
                        'order_id' => null,
                    ]);
            });
            $count++;
        }

        return $count;
    }

    /** 后台手动关闭 */
    public function closeOrder(int $orderId): Order
    {
        $order = Order::findOrFail($orderId);
        if ($order->status !== 'pending') {
            throw new \RuntimeException("仅待支付订单可关闭");
        }
        DB::transaction(function () use ($order) {
            $order->update(['status' => 'closed', 'closed_at' => now()]);
            Card::where('order_id', $order->id)
                ->where('status', Card::STATUS_LOCKED)
                ->update(['status' => Card::STATUS_UNUSED, 'locked_at' => null, 'order_id' => null]);
        });
        return $order->fresh();
    }

    /** 查询订单(凭 contact + orderNo,可选 password) */
    public function queryOrder(string $contact, string $orderNo, ?string $password = null): ?Order
    {
        $order = Order::where('order_no', $orderNo)
            ->where('contact', $contact)
            ->with('orderDeliveries')
            ->first();

        if (! $order) {
            return null;
        }

        // 若开启查询密码,且本订单有密码,才验证(订单无密码则跳过)
        $needPassword = StorefrontConfig::get('order_query_password');
        if ($needPassword) {
            $storedHash = $order->extra['query_password'] ?? null;
            if ($storedHash && ! Hash::check($password ?? '', $storedHash)) {
                return null; // 密码错,视为查不到
            }
        }

        return $order;
    }

    /** 订单详情(含发货卡密) */
    public function getOrderDetail(Order $order): array
    {
        $order->load('orderDeliveries', 'product');

        $cards = $order->status === 'paid'
            ? $order->orderDeliveries->map(fn ($d) => $d->card_content)->toArray()
            : [];

        return [
            'order_no' => $order->order_no,
            'status' => $order->status,
            'product_name' => $order->product?->name,
            'quantity' => $order->quantity,
            'amount' => $order->amount,
            'created_at' => $order->created_at,
            'paid_at' => $order->paid_at,
            'cards' => $cards,
            'extra' => $order->extra,
        ];
    }

    private function generateOrderNo(): string
    {
        return 'ORD' . now()->format('YmdHis') . strtoupper(Str::random(6));
    }
}
