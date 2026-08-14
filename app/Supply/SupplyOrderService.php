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
                        // strict 解密:密钥异常时阻断发货而非发废卡(M-9)
                        $content = $card->plainContent(true);
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

    /**
     * 取消未发货的供货订单并退还货款(安全审计 M-5)。
     * 此前 cancel 端点返回成功但什么都不做——下游余额已被扣除且不可恢复,
     * pending 单之后仍会发货,造成双方对账纠纷。
     * 事务内:行锁复检 → 释放锁定卡 → 关闭本地订单 → 退款入账 + 账本流水。
     *
     * @throws SupplyApiException 已发货/已关闭的订单不可取消(409)
     */
    public function cancelOrder(SupplierAccount $account, SupplyOrder $supplyOrder): void
    {
        DB::transaction(function () use ($account, $supplyOrder): void {
            $locked = SupplyOrder::whereKey($supplyOrder->id)
                ->where('supplier_account_id', $account->id)
                ->lockForUpdate()
                ->firstOrFail();
            $order = Order::whereKey($locked->order_id)->lockForUpdate()->firstOrFail();

            // 已发货或已关闭 → 不可取消(重复取消在此幂等拦截,409)
            if ($order->delivery_status === 'delivered' || $order->status !== 'paid') {
                throw SupplyApiException::orderNotCancelable();
            }

            // 释放仍处于锁定状态的本地卡(防御:auto card 正常即时发货,此为兜底)
            Card::where('order_id', $order->id)
                ->where('status', Card::STATUS_LOCKED)
                ->update(['status' => Card::STATUS_UNUSED, 'locked_at' => null, 'order_id' => null]);

            $order->update(['status' => 'closed', 'closed_at' => now()]);

            // 退款入账(锁账号后写账本,幂等键防重复退款)
            $lockedAccount = SupplierAccount::where('id', $account->id)->lockForUpdate()->firstOrFail();
            $amount = (int) $order->amount;
            $lockedAccount->increment('balance', $amount);
            SupplierLedgerEntry::create([
                'supplier_account_id' => $account->id,
                'order_id' => $order->id,
                'type' => SupplierLedgerEntry::TYPE_REFUND,
                'amount' => $amount,
                'balance_after' => (int) $lockedAccount->balance,
                'idempotency_key' => "supply_cancel:{$locked->id}",
                'remark' => "供货取消退款[{$locked->downstream_order_no}]",
            ]);
        });
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
