<?php

namespace App\Supply;

use App\Models\Card;
use App\Models\Order;
use App\Models\OrderDelivery;
use App\Models\Product;
use App\Models\ProductSku;
use App\Models\SupplierAccount;
use App\Models\SupplierLedgerEntry;
use App\Models\SupplyOrder;
use App\Supply\Exceptions\SupplyApiException;
use Illuminate\Database\QueryException;
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
     * @return array{supply_order_id:int,order_id:int,amount:int,cards:array<int,string>,instructions:?string,delivery_status:string,fulfillment_type:string}
     *
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
        // 解析 SKU(若有),走 SKU 级专属价;否则商品级。修正:之前硬编码 null 导致 SKU 价不生效
        $sku = null;
        if (! empty($params['sku_id'])) {
            $sku = ProductSku::where('id', $params['sku_id'])
                ->where('product_id', $product->id)
                ->first();
        }
        $unitPrice = $this->pricing->resolvePrice($account, $product, $sku);
        // 安全(H-4):供货价必须 > 0。未配置专属价且 factory_price=0 时,此前会以 0 元
        // 发货清空库存;现在直接拒绝,要求管理员先配置供货价。
        if ($unitPrice < 1) {
            throw SupplyApiException::priceNotConfigured();
        }
        $amount = $unitPrice * $qty;
        $fulfillmentType = $product->resolvedFulfillmentType();

        try {
            $supplyOrderId = DB::transaction(function () use ($account, $product, $params, $mode, $qty, $amount, $fulfillmentType) {
                // 锁账号
                $locked = SupplierAccount::where('id', $account->id)->lockForUpdate()->firstOrFail();

                // 余额检查(锁账号后,锁卡前,避免后续卡锁的死锁)
                if ($locked->balance < $amount) {
                    throw SupplyApiException::insufficientBalance();
                }

                $cards = collect();
                if ($fulfillmentType === Product::FULFILLMENT_AUTO_CARD) {
                    // 自动卡密才锁本地卡库存；其他履约类型没有本地卡池。
                    $cards = Card::where('product_id', $product->id)
                        ->where('status', Card::STATUS_UNUSED)
                        ->lockForUpdate()
                        ->limit($qty)
                        ->get();

                    if ($cards->count() < $qty) {
                        throw SupplyApiException::insufficientStock();
                    }
                } elseif ($fulfillmentType === Product::FULFILLMENT_FIXED && trim((string) $product->delivery_message) === '') {
                    throw SupplyApiException::productUnavailable();
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
                    'delivery_status' => 'pending',
                    'fulfillment_type_snapshot' => $fulfillmentType,
                    'paid_at' => now(),
                    'source' => 'supply',
                    'instructions_snapshot' => $product->leave_message ?: null,
                    'delivery_message_snapshot' => $fulfillmentType === Product::FULFILLMENT_FIXED
                        ? ($product->delivery_message ?: null)
                        : null,
                ]);

                if ($fulfillmentType === Product::FULFILLMENT_AUTO_CARD) {
                    foreach ($cards as $card) {
                        $content = $card->plainContent();
                        $card->update(['status' => Card::STATUS_USED, 'order_id' => $order->id, 'used_at' => now()]);
                        OrderDelivery::create([
                            'order_id' => $order->id,
                            'product_id' => $product->id,
                            'card_content' => $content,
                            'delivered_mode' => 'status',
                            'delivered_at' => now(),
                        ]);
                    }
                    $order->update(['delivery_status' => 'delivered']);
                } elseif ($fulfillmentType === Product::FULFILLMENT_FIXED) {
                    OrderDelivery::create([
                        'order_id' => $order->id,
                        'product_id' => $product->id,
                        'card_content' => $order->delivery_message_snapshot,
                        'delivered_mode' => 'fixed',
                        'delivered_at' => now(),
                    ]);
                    $order->update(['delivery_status' => 'delivered']);
                }

                // 写 supply_orders(唯一约束 supplier_account_id+downstream_order_no 兜底幂等)
                $supplyOrder = SupplyOrder::create([
                    'supplier_account_id' => $account->id,
                    'order_id' => $order->id,
                    'downstream_order_no' => $params['downstream_order_no'],
                    'fulfillment_mode' => $mode,
                    'callback_url' => $params['callback_url'] ?? null,
                    'callback_status' => ! empty($params['callback_url'])
                        && in_array($fulfillmentType, [Product::FULFILLMENT_MANUAL, Product::FULFILLMENT_UPSTREAM], true)
                        ? SupplyOrder::CALLBACK_PENDING
                        : null,
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

                return $supplyOrder->id;
            });

            $supplyOrder = SupplyOrder::findOrFail($supplyOrderId);
            if ($fulfillmentType === Product::FULFILLMENT_UPSTREAM) {
                $order = $supplyOrder->order()->with('product')->firstOrFail();
                app(UpstreamOrderService::class)->fulfill($order);
                $supplyOrder->refresh();
            }

            return $this->formatResult($supplyOrder);
        } catch (QueryException $e) {
            // 并发竞态:另一请求已插入同 downstream_order_no → 当幂等重试
            if ($this->isUniqueViolation($e)) {
                $existing = SupplyOrder::where('supplier_account_id', $account->id)
                    ->where('downstream_order_no', $params['downstream_order_no'])
                    ->first();
                if ($existing) {
                    return $this->formatResult($existing);
                }
            }
            throw $e;
        }
    }

    /** 判断是否唯一约束冲突(MySQL 1062 / SQLite/PG "Unique constraint") */
    private function isUniqueViolation(QueryException $e): bool
    {
        $code = $e->errorInfo[1] ?? null;
        $msg = (string) $e->getMessage();

        return $code === 1062 // MySQL duplicate entry
            || str_contains($msg, 'uniq_supply_downstream_no')
            || str_contains($msg, 'UNIQUE constraint failed'); // SQLite
    }

    private function formatResult(SupplyOrder $supplyOrder): array
    {
        $order = $supplyOrder->order()->with('orderDeliveries')->firstOrFail();
        $cards = $order->orderDeliveries->pluck('card_content')->all();

        return [
            'supply_order_id' => $supplyOrder->id,
            'order_id' => $order->id,
            'amount' => (int) $order->amount,
            'cards' => $cards,
            'instructions' => $order->delivery_status === 'delivered'
                ? ($order->instructions_snapshot ?: null)
                : null,
            'delivery_status' => $order->delivery_status,
            'fulfillment_type' => $order->fulfillment_type_snapshot,
        ];
    }

    private function generateOrderNo(): string
    {
        return 'SUP'.now()->format('YmdHis').strtoupper(Str::random(6));
    }
}
