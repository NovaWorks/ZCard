<?php

namespace App\Supply;

use App\Jobs\FetchFromUpstream;
use App\Models\Card;
use App\Models\Order;
use App\Models\OrderDelivery;
use App\Models\SupplySource;
use App\Support\CardCipher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * 下游拿货编排(spec §5.3)
 * 顾客订单付款 → 触发去上游拿货。同步试 → 失败转异步 Job。
 */
class UpstreamOrderService
{
    public function __construct(private readonly SupplyManager $manager) {}

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
                'callback_url' => rtrim(config('app.url'), '/') . '/api/supply/callback',
            ]);
            $order->update(['upstream_order_id' => $upstream->id, 'upstream_source_id' => $source->id]);
        }

        // 已发卡?
        if ($upstream->fulfillment && $upstream->fulfillment->isDelivered() && ! empty($upstream->fulfillment->cards)) {
            $this->writeCards($order, $upstream->fulfillment->cards);
        } elseif ($upstream->status === 'canceled') {
            $this->handleUpstreamCanceled($order, $source);
        }
        // 仍 pending:不动,等 Job 重试或回调
    }

    /** 把上游卡密写入本地订单(加密存 Card + 明文写 OrderDelivery 快照供顾客查看) */
    public function writeCards(Order $order, array $cards): void
    {
        DB::transaction(function () use ($order, $cards) {
            $locked = Order::where('id', $order->id)->lockForUpdate()->firstOrFail();
            if ($locked->delivery_status === 'delivered') {
                return; // 幂等
            }

            foreach ($cards as $plainContent) {
                // 加密存 Card(与 CardImportService 一致,plainContent() 才能正确解密)
                Card::create([
                    'product_id' => $locked->product_id,
                    'content' => CardCipher::encrypt($plainContent),
                    'content_hash' => hash('sha256', $plainContent . uniqid()),
                    'status' => Card::STATUS_USED,
                    'order_id' => $locked->id,
                    'used_at' => now(),
                ]);
                // 明文写 OrderDelivery 快照(顾客订单页读 orderDeliveries.card_content)
                OrderDelivery::create([
                    'order_id' => $locked->id,
                    'product_id' => $locked->product_id,
                    'card_content' => $plainContent,
                    'delivered_mode' => 'status',
                    'delivered_at' => now(),
                ]);
            }
            $locked->update(['delivery_status' => 'delivered']);
        });
    }

    /** 上游取消 → 按配置处理 */
    private function handleUpstreamCanceled(Order $order, SupplySource $source): void
    {
        $action = $source->settings['failure_action'] ?? 'manual';
        if ($action === 'auto_refund') {
            $order->update(['status' => 'closed', 'delivery_status' => 'failed']);
            Log::info("supply order auto-refunded: {$order->order_no}");
        } else {
            $order->update(['delivery_status' => 'failed']);
            Log::warning("supply order needs manual intervention: {$order->order_no}");
        }
    }

    /** 重试用尽(Job 调用) */
    public function handleTimeout(Order $order, SupplySource $source): void
    {
        $action = $source->settings['failure_action'] ?? 'manual';
        if ($action === 'auto_refund') {
            $order->update(['status' => 'closed', 'delivery_status' => 'failed']);
        } else {
            $order->update(['delivery_status' => 'failed']);
        }
        Log::warning("supply upstream fetch timeout: {$order->order_no}");
    }
}
