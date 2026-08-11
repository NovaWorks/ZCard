<?php

namespace App\Support;

use App\Events\OrderPaid;
use App\Models\Card;
use App\Models\Order;
use App\Models\Product;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class DeliveryService
{
    public function __construct(private readonly FulfillmentService $fulfillment) {}

    /** 监听 OrderPaid 事件 */
    public function handle(OrderPaid $event): void
    {
        $order = $event->order->loadMissing('product');
        // 供货 API 在 SupplyOrderService 内完成履约，不重复消费站内支付事件。
        if ($order->source === 'supply') {
            return;
        }

        $type = $order->fulfillment_type_snapshot ?: $order->product?->resolvedFulfillmentType();
        if (in_array($type, [Product::FULFILLMENT_UPSTREAM, Product::FULFILLMENT_MANUAL], true)) {
            return;
        }

        if ($type === Product::FULFILLMENT_FIXED) {
            $content = trim((string) $order->delivery_message_snapshot);
            if ($content === '') {
                throw new \RuntimeException('固定发货内容为空，无法完成发货');
            }
            $this->fulfillment->fulfill($order, [$content], 'fixed');

            return;
        }

        $this->deliver($order);
    }

    /** 发货:按 delivery_mode 写快照 + 处理卡密 */
    public function deliver(Order $order): void
    {
        $order->load('product');
        $product = $order->product;
        $mode = $product->delivery_mode; // status | delete

        $cards = Card::where('order_id', $order->id)->get();
        if ($cards->isEmpty()) {
            throw new \RuntimeException('订单未找到已锁定卡密，无法完成自动发货');
        }

        $contents = $cards->map(fn ($card) => $card->plainContent())->all();
        $delivered = DB::transaction(function () use ($order, $cards, $contents, $mode) {
            $completed = $this->fulfillment->fulfill($order, $contents, $mode, notify: false);
            if (! $completed) {
                return false;
            }

            foreach ($cards as $card) {
                if ($mode === 'delete') {
                    $card->delete();
                } else {
                    $card->update(['status' => Card::STATUS_USED, 'used_at' => now()]);
                }
            }

            return true;
        });

        if ($delivered) {
            $this->fulfillment->notify($order->fresh(['product', 'orderDeliveries']));
            Log::info("订单 {$order->order_no} 发货完成", ['cards' => $cards->count(), 'mode' => $mode]);
        }
    }
}
