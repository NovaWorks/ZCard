<?php

namespace App\Support;

use App\Events\OrderPaid;
use App\Models\Card;
use App\Models\Order;
use App\Models\OrderDelivery;
use Illuminate\Support\Facades\Log;

class DeliveryService
{
    /** 监听 OrderPaid 事件 */
    public function handle(OrderPaid $event): void
    {
        $this->deliver($event->order);
    }

    /** 发货:按 delivery_mode 写快照 + 处理卡密 */
    public function deliver(Order $order): void
    {
        $order->load('product');
        $product = $order->product;
        $mode = $product->delivery_mode; // status | delete

        $cards = Card::where('order_id', $order->id)->get();

        foreach ($cards as $card) {
            // 写发货快照(明文)
            OrderDelivery::create([
                'order_id' => $order->id,
                'product_id' => $order->product_id,
                'card_content' => $card->plainContent(),
                'delivered_mode' => $mode,
                'delivered_at' => now(),
            ]);

            // 按模式处理
            if ($mode === 'delete') {
                $card->delete();
            } else {
                $card->update(['status' => Card::STATUS_USED, 'used_at' => now()]);
            }
        }

        // 更新订单发货状态
        if ($cards->count() > 0) {
            $order->update(['delivery_status' => 'delivered']);
        }

        // 发送邮件通知(如果开启了邮件功能且联系邮箱有效)
        if ($order->contact && filter_var($order->contact, FILTER_VALIDATE_EMAIL)) {
            $cardContents = $cards->map(fn ($c) => $c->plainContent())->toArray();
            // 如果卡密已删除(delete 模式),用发货快照
            if (empty($cardContents)) {
                $cardContents = OrderDelivery::where('order_id', $order->id)->pluck('card_content')->toArray();
            }
            try {
                MailService::sendDeliveryNotification($order->contact, [
                    'order_no' => $order->order_no,
                    'product_name' => $product->name,
                    'quantity' => $order->quantity,
                    'cards' => $cardContents,
                ]);
            } catch (\Throwable $e) {
                Log::warning("订单 {$order->order_no} 邮件通知失败: {$e->getMessage()}");
            }
        }

        Log::info("订单 {$order->order_no} 发货完成", ['cards' => $cards->count(), 'mode' => $mode]);
    }
}
