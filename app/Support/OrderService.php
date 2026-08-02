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
    public function createOrder(int $productId, ?int $skuId, int $qty, array $customer, ?string $displayCurrency = null): Order
    {
        $product = \App\Models\Product::with('skus')->findOrFail($productId);
        $unitPrice = $skuId
            ? ($product->skus->firstWhere('id', $skuId)?->price ?? $product->price)
            : $product->price;
        $amount = $unitPrice * $qty;

        // 分站定价(spec §5):读 subsite,按分站加价
        $subsite = request()->attributes->get('subsite');
        $subsiteId = null;
        $subsiteDomain = null;
        $baseUnitPrice = $unitPrice;
        $profitEligible = true;
        $profitBlockReason = null;
        if ($subsite) {
            $pricing = app(\App\Support\SubsitePricingService::class)
                ->resolveUnitPrice($product, $skuId ? $product->skus->firstWhere('id', $skuId) : null, $subsite);
            $unitPrice = $pricing['price'];
            $amount = $unitPrice * $qty; // 重算金额(加价后)
            $subsiteId = $subsite->id;
            $subsiteDomain = request()->host();
            // 防自购(spec §6)
            $buyerId = $customer['user_id'] ?? null;
            if ($buyerId && $buyerId == $subsite->user_id) {
                $profitEligible = false;
                $profitBlockReason = 'self_dealing_owner';
            } elseif ($buyerId) {
                $upline = \App\Models\User::find($buyerId);
                for ($i = 0; $i < 3 && $upline && $upline->pid; $i++) {
                    $upline = \App\Models\User::find($upline->pid);
                    if ($upline && $upline->id == $subsite->user_id) {
                        $profitEligible = false;
                        $profitBlockReason = 'self_dealing_upline';
                        break;
                    }
                }
            }
        }

        // 优惠券处理
        $couponCode = $customer['coupon_code'] ?? null;
        $discountAmount = 0;
        $coupon = null;

        if ($couponCode && $qty === 1 && ! $subsite) {
            $result = \App\Support\CouponService::validate($couponCode, $productId, $amount);
            $discountAmount = $result['discount'];
            $coupon = $result['coupon'];
            $amount = max(0, $amount - $discountAmount);
        }

        return DB::transaction(function () use ($productId, $skuId, $qty, $customer, $product, $amount, $discountAmount, $couponCode, $coupon, $displayCurrency, $subsite, $subsiteId, $subsiteDomain, $baseUnitPrice, $unitPrice, $profitEligible, $profitBlockReason) {
            // 上游商品:跳过锁卡(无本地卡),付款后由 UpstreamOrderService 拿货
            $isUpstream = ! empty($product->upstream_source_id);
            $cards = collect();

            if (! $isUpstream) {
                // 本地商品:锁卡(FOR UPDATE 防并发超卖)
                $cards = Card::where('product_id', $productId)
                    ->where('status', Card::STATUS_UNUSED)
                    ->lockForUpdate()
                    ->limit($qty)
                    ->get();

                if ($cards->count() < $qty) {
                    throw new InsufficientStockException(__('messages.insufficient_stock', ['need' => $qty, 'have' => $cards->count()]));
                }
            }

            $extra = array_merge(
                $skuId ? ['sku_id' => $skuId, 'sku_name' => $product->skus->firstWhere('id', $skuId)?->name] : [],
                ['control' => $customer['extra'] ?? []],
            );

            // 查询密码加密存
            if (! empty($customer['password'])) {
                $extra['query_password'] = Hash::make($customer['password']);
            }

            // 创建订单(含成本/SKU 快照)
            $skuName = $skuId ? ($product->skus->firstWhere('id', $skuId)?->name) : null;
            $unitCost = (int) $product->factory_price;

            // 货币快照(spec §3.5):下单瞬间锁定显示汇率
            $currencySvc = app(\App\Support\CurrencyService::class);
            $baseCur = $currencySvc->getBaseCurrency();
            $dispCur = $displayCurrency ?: $baseCur;
            $conv = $currencySvc->convert((int) $amount, $dispCur);

            $order = Order::create([
                'order_no' => $this->generateOrderNo(),
                'merchant_id' => $product->merchant_id,
                'user_id' => $customer['user_id'] ?? null,
                'product_id' => $productId,
                'quantity' => $qty,
                'amount' => $amount,
                'base_currency' => $baseCur,
                'display_currency' => $conv['currency'],
                'exchange_rate' => $conv['rate'],
                'amount_display' => $conv['amount'],
                'subsite_id' => $subsiteId,
                'subsite_domain' => $subsiteDomain,
                'subsite_profit' => $profitEligible ? (($unitPrice - $baseUnitPrice) * $qty) : 0,
                'coupon_code' => $couponCode,
                'discount_amount' => $discountAmount,
                'cost' => $unitCost * $qty,
                'sku_name' => $skuName,
                'status' => 'pending',
                'delivery_status' => 'pending',
                'contact' => $customer['contact'] ?? null,
                'create_ip' => $customer['create_ip'] ?? null,
                'create_device' => $customer['create_device'] ?? null,
                'extra' => $extra,
            ]);

            // 核销优惠券
            if ($coupon) {
                \App\Support\CouponService::apply($coupon, $order->id, $customer['user_id'] ?? null);
            }

            // 分站订单定价快照(spec §5)
            if ($subsiteId) {
                \App\Models\SubsiteOrderSnapshot::create([
                    'order_id' => $order->id,
                    'merchant_id' => $subsiteId,
                    'domain' => $subsiteDomain,
                    'reseller_user_id' => $subsite->user_id,
                    'buyer_id' => $customer['user_id'] ?? null,
                    'base_amount' => $baseUnitPrice * $qty,
                    'reseller_amount' => $amount,
                    'profit_amount' => $profitEligible ? (($unitPrice - $baseUnitPrice) * $qty) : 0,
                    'profit_eligible' => $profitEligible,
                    'profit_block_reason' => $profitBlockReason,
                    'pricing_snapshot' => ['unit_base' => $baseUnitPrice, 'unit_reseller' => $unitPrice, 'qty' => $qty],
                    'risk_snapshot' => ['profit_eligible' => $profitEligible, 'profit_block_reason' => $profitBlockReason],
                ]);
            }

            // 锁定卡密(仅本地商品;上游商品付款后由 UpstreamOrderService 拿货)
            if (! $isUpstream) {
                $cards->each->update([
                    'status' => Card::STATUS_LOCKED,
                    'locked_at' => now(),
                    'order_id' => $order->id,
                ]);
            }

            return $order;
        });
    }

    /**
     * 标记支付成功(状态机 pending→paid)。
     * 发卡通过 OrderPaid 事件触发(同步监听),事件在事务内派发,
     * 因此发卡与状态变更同事务,保证一致性(参考 acg-faka orderSuccess)。
     * 若发卡失败,整个事务回滚,订单回到 pending,第三方会重试回调。
     */
    public function markPaid(string $orderNo): Order
    {
        $order = DB::transaction(function () use ($orderNo) {
            $order = Order::where('order_no', $orderNo)->lockForUpdate()->firstOrFail();
            if ($order->status !== 'pending') {
                throw new \RuntimeException("订单状态异常: {$order->status},无法支付");
            }
            // 快照支付渠道(从 payments 关联取成功的渠道码)
            $paymentChannel = \App\Models\Payment::where('order_id', $order->id)
                ->where('status', 'success')->value('channel');
            $order->update([
                'status' => 'paid',
                'paid_at' => now(),
                'payment_channel' => $paymentChannel ?? $order->payment_channel,
            ]);
            // 事件在事务内派发,监听器(DeliveryService)同步执行,
            // 确保发卡与状态变更原子一致
            event(new OrderPaid($order));
            return $order;
        });

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

    /**
     * 搜索订单:单关键字智能匹配 order_no 或 contact,返回历史订单列表。
     * 若开启查询密码,则 password 必填,仅返回密码匹配(或订单无密码)的记录。
     *
     * @return array<int, array>
     */
    public function searchOrders(string $keyword, ?string $password = null): array
    {
        $kw = trim($keyword);
        $query = Order::with(['product:id,name,cover', 'orderDeliveries:id,order_id,card_content'])
            ->where(fn ($q) => $q->where('order_no', $kw)->orWhere('contact', $kw))
            ->orderByDesc('id')
            ->limit(50);

        $needPassword = StorefrontConfig::get('order_query_password');
        $orders = $query->get();

        // 密码过滤:仅保留"无密码"或"密码匹配"的订单
        if ($needPassword) {
            $orders = $orders->filter(function ($o) use ($password) {
                $storedHash = $o->extra['query_password'] ?? null;
                // 订单未设密码 → 放行
                if (! $storedHash) {
                    return true;
                }
                // 订单有密码 → 校验
                return Hash::check($password ?? '', $storedHash);
            })->values();
        }

        return $orders->map(fn ($o) => [
            'order_no' => $o->order_no,
            'product_name' => $o->product?->name,
            'product_cover' => $o->product?->cover,
            'quantity' => $o->quantity,
            'amount' => $o->amount,
            'amount_display' => $o->amount_display,
            'display_currency' => $o->display_currency,
            'exchange_rate' => $o->exchange_rate,
            'status' => $o->status,
            'created_at' => $o->created_at?->toDateTimeString(),
            'paid_at' => $o->paid_at?->toDateTimeString(),
            'cards' => $o->status === 'paid'
                ? ($o->orderDeliveries?->map(fn ($d) => $d->card_content)->toArray() ?? [])
                : [],
        ])->toArray();
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
            'amount_display' => $order->amount_display,
            'display_currency' => $order->display_currency,
            'exchange_rate' => $order->exchange_rate,
            'created_at' => $order->created_at,
            'paid_at' => $order->paid_at,
            'cards' => $cards,
            'extra' => $order->extra,
        ];
    }

    /** 我的订单(登录用户的历史订单) */
    public function myOrders(int $userId): array
    {
        $orders = \App\Models\Order::where('user_id', $userId)
            ->with(['product:id,name,cover', 'orderDeliveries:id,order_id,card_content'])
            ->orderByDesc('id')
            ->limit(50)
            ->get();

        return $orders->map(fn ($o) => [
            'order_no' => $o->order_no,
            'product_name' => $o->product?->name,
            'product_cover' => $o->product?->cover,
            'quantity' => $o->quantity,
            'amount' => $o->amount,
            'amount_display' => $o->amount_display,
            'display_currency' => $o->display_currency,
            'exchange_rate' => $o->exchange_rate,
            'status' => $o->status,
            'created_at' => $o->created_at?->toDateTimeString(),
            'paid_at' => $o->paid_at?->toDateTimeString(),
            'cards' => $o->orderDeliveries?->map(fn ($d) => $d->card_content)->toArray() ?? [],
        ])->toArray();
    }

    private function generateOrderNo(): string
    {
        return 'ORD' . now()->format('YmdHis') . strtoupper(Str::random(6));
    }
}
