<?php

namespace App\Supply;

use App\Models\Card;
use App\Models\Order;
use App\Models\Product;
use App\Models\SupplierAccount;
use App\Models\SupplierLedgerEntry;
use App\Models\SupplyOrder;
use App\Supply\Exceptions\SupplyApiException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * 供货下单服务(spec §4.4)
 * 复用卡库存 lockForUpdate 防超卖;扣预存余额;写幂等 supply_orders。
 */
class SupplyOrderService
{
    public function __construct(private readonly SupplyPricingService $pricing) {}

    /**
     * @param  array{product_id:int,sku_id?:int,quantity:int,downstream_order_no:string,contact?:string,callback_url?:string}  $params
     * @param  'sync'|'async'  $mode
     * @return array{supply_order_id:int,order_id:int,amount:int,cards:array<int,string>}
     * @throws SupplyApiException
     */
    public function createOrder(SupplierAccount $account, array $params, string $mode = 'sync'): array
    {
        // 幂等:同 downstream_order_no 已存在则返回
        $existing = SupplyOrder::where('supplier_account_id', $account->id)
            ->where('downstream_order_no', $params['downstream_order_no'])
            ->first();
        if ($existing) {
            return $this->formatResult($existing);
        }

        $product = Product::find($params['product_id']);
        if (! $product || $product->status != 1) {
            throw SupplyApiException::productUnavailable();
        }

        $qty = $params['quantity'];
        $unitPrice = $this->pricing->resolvePrice($account, $product, null);
        $amount = $unitPrice * $qty;

        return DB::transaction(function () use ($account, $product, $params, $mode, $qty, $amount) {
            // 锁账号
            $locked = SupplierAccount::where('id', $account->id)->lockForUpdate()->firstOrFail();

            // 余额检查(锁账号后,锁卡前,避免后续卡锁的死锁)
            if ($locked->balance < $amount) {
                throw SupplyApiException::insufficientBalance();
            }

            // 锁卡(防超卖)
            $cards = Card::where('product_id', $product->id)
                ->where('status', Card::STATUS_UNUSED)
                ->lockForUpdate()
                ->limit($qty)
                ->get();

            if ($cards->count() < $qty) {
                throw SupplyApiException::insufficientStock();
            }

            // 创建本地 order(source=supply,不走支付通道)
            $order = Order::create([
                'order_no' => $this->generateOrderNo(),
                'merchant_id' => $product->merchant_id,
                'product_id' => $product->id,
                'quantity' => $qty,
                'amount' => $amount,
                'cost' => (int) $product->factory_price * $qty,
                'status' => 'paid',
                'delivery_status' => 'delivered',
                'paid_at' => now(),
                'source' => 'supply',
            ]);

            // 同步发卡(锁卡后标记 used,事务内原子)
            foreach ($cards as $card) {
                $card->update(['status' => Card::STATUS_USED, 'order_id' => $order->id, 'used_at' => now()]);
            }

            // 写 supply_orders(唯一约束 supplier_account_id+downstream_order_no 兜底幂等)
            $supplyOrder = SupplyOrder::create([
                'supplier_account_id' => $account->id,
                'order_id' => $order->id,
                'downstream_order_no' => $params['downstream_order_no'],
                'fulfillment_mode' => $mode,
                'callback_url' => $params['callback_url'] ?? null,
            ]);

            // 扣余额 + 账本(balance_after 快照取 decrement 后内存值)
            $locked->decrement('balance', $amount);
            SupplierLedgerEntry::create([
                'supplier_account_id' => $account->id,
                'order_id' => $order->id,
                'type' => SupplierLedgerEntry::TYPE_ORDER,
                'amount' => -$amount,
                'balance_after' => (int) $locked->balance,
                'idempotency_key' => "supply_order:{$supplyOrder->id}",
                'remark' => "供货下单[{$params['downstream_order_no']}]",
            ]);

            return [
                'supply_order_id' => $supplyOrder->id,
                'order_id' => $order->id,
                'amount' => $amount,
                'cards' => $cards->pluck('content')->all(),
            ];
        });
    }

    private function formatResult(SupplyOrder $supplyOrder): array
    {
        $order = $supplyOrder->order;
        $cards = Card::where('order_id', $order->id)
            ->where('status', Card::STATUS_USED)
            ->pluck('content')
            ->all();

        return [
            'supply_order_id' => $supplyOrder->id,
            'order_id' => $order->id,
            'amount' => (int) $order->amount,
            'cards' => $cards,
        ];
    }

    private function generateOrderNo(): string
    {
        return 'SUP' . now()->format('YmdHis') . strtoupper(Str::random(6));
    }
}
