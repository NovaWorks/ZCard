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

        // 安全(M-2):只取「本订单绑定的锁定卡」——此前不过滤状态,管理员中途
        // unlock(卡已归池)/disable 的卡也会照发,出现发禁用卡或张数不符。
        $cards = Card::where('order_id', $order->id)
            ->where('status', Card::STATUS_LOCKED)
            ->get();
        if ($cards->isEmpty()) {
            throw new \RuntimeException('订单未找到已锁定卡密，无法完成自动发货');
        }
        if ($cards->count() < (int) $order->quantity) {
            // 锁定卡少于订单数量(如中途被解锁/禁用):显式失败进日志与回调,
            // 不再静默发不足量的卡。抛出的异常会随 markPaid 事务回滚并触发网关重试。
            throw new \RuntimeException(
                "订单 {$order->order_no} 锁定卡密不足: 需要 {$order->quantity} 张, 实际锁定 {$cards->count()} 张"
            );
        }

        // 发货链路用 strict 解密:密钥变更/数据损坏时阻断发货并告警,
        // 不把密文当卡密发给买家(M-9)
        $contents = $cards->map(fn ($card) => $card->plainContent(true))->all();
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
