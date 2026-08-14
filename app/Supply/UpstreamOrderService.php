<?php

namespace App\Supply;

use App\Jobs\FetchFromUpstream;
use App\Models\Bill;
use App\Models\Card;
use App\Models\Order;
use App\Models\SupplyOrder;
use App\Models\SupplySource;
use App\Supply\Exceptions\SupplyApiException;
use App\Support\BillService;
use App\Support\CardCipher;
use App\Support\FulfillmentService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * 下游拿货编排(spec §5.3)
 * 顾客订单付款 → 触发去上游拿货。同步试 → 失败转异步 Job。
 */
class UpstreamOrderService
{
    public function __construct(
        private readonly SupplyManager $manager,
        private readonly FulfillmentService $fulfillment,
    ) {}

    /**
     * 履约订单(从上游拿货填卡密)。由 FetchFromUpstreamOnOrderPaid 监听器调用。
     */
    public function fulfill(Order $order): void
    {
        $product = $order->product;
        if (! $product || ! $product->upstream_source_id) {
            return; // 非上游商品,跳过
        }

        $source = SupplySource::find($product->upstream_source_id);
        if (! $source) {
            return;
        }

        $mode = $source->settings['fulfillment_mode'] ?? 'sync';

        if ($mode === 'async') {
            FetchFromUpstream::dispatch($order->id);

            return;
        }

        // sync:先同步试
        try {
            $this->fetchFromUpstream($order, $source);
        } catch (Throwable $e) {
            Log::warning("supply sync fetch failed, fallback to async: {$e->getMessage()}");
            $order->update(['delivery_status' => 'pending']);
            FetchFromUpstream::dispatch($order->id);
        }
    }

    /** 实际调上游下单拿货 */
    public function fetchFromUpstream(Order $order, SupplySource $source): void
    {
        $driver = $this->manager->driver($source);

        // 已有 upstream_order_id?查单
        if ($order->upstream_order_id) {
            $upstream = $driver->getOrder($order->upstream_order_id);
        } else {
            $product = $order->product;
            $upstream = $driver->createOrder([
                'product_code' => $product->upstream_product_code,
                'quantity' => $order->quantity,
                'downstream_order_no' => $order->order_no, // 幂等
                'callback_url' => rtrim(config('app.url'), '/').'/api/supply/callback',
            ]);
            $order->update(['upstream_order_id' => $upstream->id, 'upstream_source_id' => $source->id]);
        }

        // 已发卡?
        if ($upstream->fulfillment && $upstream->fulfillment->isDelivered()) {
            $this->writeFulfillment(
                $order,
                $upstream->fulfillment->cards,
                $upstream->fulfillment->instructions,
            );
        } elseif ($upstream->status === 'canceled') {
            $this->handleUpstreamCanceled($order, $source);
        }
        // 仍 pending:不动,等 Job 重试或回调
    }

    /** 把上游发货物与付款后说明在同一事务中写入本地订单。 */
    public function writeFulfillment(Order $order, array $cards, ?string $instructions = null): void
    {
        $delivered = DB::transaction(function () use ($order, $cards, $instructions) {
            $locked = Order::where('id', $order->id)->lockForUpdate()->firstOrFail();
            if ($locked->delivery_status === 'delivered') {
                return false; // 幂等
            }

            foreach ($cards as $plainContent) {
                // 加密存 Card(与 CardImportService 一致,plainContent() 才能正确解密)
                Card::create([
                    'product_id' => $locked->product_id,
                    'content' => CardCipher::encrypt($plainContent),
                    'content_hash' => hash('sha256', $plainContent.uniqid()),
                    'status' => Card::STATUS_USED,
                    'order_id' => $locked->id,
                    'used_at' => now(),
                ]);
            }

            return $this->fulfillment->fulfill($locked, $cards, 'upstream', $instructions, notify: false);
        });

        if ($delivered) {
            $this->fulfillment->notify($order->fresh(['product', 'orderDeliveries']));
        }
    }

    /** 兼容原有调用方；新代码应传完整履约对象。 */
    public function writeCards(Order $order, array $cards): void
    {
        $this->writeFulfillment($order, $cards);
    }

    /** 上游取消 → 按配置处理 */
    private function handleUpstreamCanceled(Order $order, SupplySource $source): void
    {
        $this->handleFailure($order, $source, 'canceled');
    }

    /** 重试用尽(Job 调用) */
    public function handleTimeout(Order $order, SupplySource $source): void
    {
        $this->handleFailure($order, $source, 'timeout');
    }

    /** 失败处理与迟到发货串行化；只对站内可控余额执行真正的自动退款。 */
    private function handleFailure(Order $order, SupplySource $source, string $reason): void
    {
        $action = $source->settings['failure_action'] ?? 'manual';
        if ($action !== 'auto_refund') {
            DB::transaction(function () use ($order): void {
                $locked = Order::whereKey($order->id)->lockForUpdate()->first();
                if ($locked && $locked->delivery_status !== 'delivered') {
                    $locked->update(['delivery_status' => 'failed']);
                }
            });
            Log::warning("supply upstream {$reason}, manual intervention required: {$order->order_no}");

            return;
        }

        if ($order->source === 'supply') {
            $supplyOrder = SupplyOrder::with('supplierAccount')->where('order_id', $order->id)->first();
            if ($supplyOrder?->supplierAccount) {
                try {
                    app(SupplyOrderService::class)->cancelOrder($supplyOrder->supplierAccount, $supplyOrder);
                } catch (SupplyApiException) {
                    $fresh = $order->fresh();
                    if ($fresh?->delivery_status === 'delivered') {
                        return;
                    }
                    if ($fresh?->status !== 'closed') {
                        throw new \RuntimeException("供货订单自动退款失败: {$order->order_no}");
                    }
                }
                Order::whereKey($order->id)
                    ->where('delivery_status', '!=', 'delivered')
                    ->update(['delivery_status' => 'failed']);
                Log::info("supply upstream {$reason}, supplier balance refunded: {$order->order_no}");

                return;
            }
        }

        $result = DB::transaction(function () use ($order): string {
            $locked = Order::whereKey($order->id)->lockForUpdate()->first();
            if (! $locked || $locked->delivery_status === 'delivered') {
                return 'delivered';
            }
            if ($locked->status === 'refunded') {
                return 'refunded';
            }
            if ($locked->status === 'paid' && $locked->payment_channel === 'balance' && $locked->user_id) {
                BillService::record(
                    $locked->user_id,
                    (int) $locked->amount,
                    Bill::TYPE_INCOME,
                    "上游拿货失败退款 {$locked->order_no}",
                    $locked->id,
                );
                $locked->update(['status' => 'refunded', 'delivery_status' => 'failed']);

                return 'refunded';
            }

            // PaymentDriver 尚无退款契约，不能把外部网关付款伪装成已退款。
            $locked->update(['delivery_status' => 'failed']);

            return 'manual';
        });

        if ($result === 'refunded') {
            Log::info("supply upstream {$reason}, customer balance refunded: {$order->order_no}");
        } elseif ($result === 'manual') {
            Log::warning("supply upstream {$reason}, gateway refund requires manual intervention: {$order->order_no}");
        }
    }
}
