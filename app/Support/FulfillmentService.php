<?php

namespace App\Support;

use App\Models\Order;
use App\Models\OrderDelivery;
use App\Supply\SupplyCallbackService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/** 统一写入订单发货内容，并保证重复回调/重复提交不会二次发货。 */
class FulfillmentService
{
    public function __construct(private readonly SupplyCallbackService $supplyCallback) {}

    /**
     * @param  array<int, string>  $contents
     * @return bool 本次调用是否完成了首次发货
     */
    public function fulfill(
        Order $order,
        array $contents,
        string $mode,
        ?string $instructions = null,
        bool $notify = true,
    ): bool {
        $contents = array_values(array_filter(array_map(
            fn ($content) => is_scalar($content) ? trim((string) $content) : '',
            $contents,
        ), fn ($content) => $content !== ''));

        $delivered = DB::transaction(function () use ($order, $contents, $mode, $instructions) {
            $locked = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();
            if ($locked->delivery_status === 'delivered') {
                return false;
            }

            foreach ($contents as $content) {
                OrderDelivery::create([
                    'order_id' => $locked->id,
                    'product_id' => $locked->product_id,
                    'card_content' => $content,
                    'delivered_mode' => $mode,
                    'delivered_at' => now(),
                ]);
            }

            $update = ['delivery_status' => 'delivered'];
            if ($instructions !== null) {
                $sanitizedInstructions = HtmlContentSanitizer::sanitize($instructions);
                $update['instructions_snapshot'] = trim($sanitizedInstructions) !== '' ? $sanitizedInstructions : null;
            }
            $locked->update($update);

            return true;
        });

        if ($delivered && $notify) {
            $this->notify($order->fresh(['product', 'orderDeliveries']));
        }

        return $delivered;
    }

    /** 仅在首次从待发货变为已发货时由 fulfill() 调用。 */
    public function notify(Order $order): void
    {
        if (DB::transactionLevel() > 0) {
            $orderId = $order->id;
            DB::afterCommit(function () use ($orderId) {
                $fresh = Order::with(['product', 'orderDeliveries'])->find($orderId);
                if ($fresh) {
                    $this->sendNotificationNow($fresh);
                }
            });

            return;
        }

        $this->sendNotificationNow($order);
    }

    private function sendNotificationNow(Order $order): void
    {
        $product = $order->product;
        $contents = $order->orderDeliveries->pluck('card_content')->all();

        if ($order->contact && filter_var($order->contact, FILTER_VALIDATE_EMAIL)) {
            try {
                MailService::sendDeliveryNotification($order->contact, [
                    'order_no' => $order->order_no,
                    'product_name' => $product?->name ?? '-',
                    'quantity' => $order->quantity,
                    'cards' => $contents,
                    'instructions' => $order->instructions_snapshot,
                ]);
            } catch (\Throwable $e) {
                Log::warning("订单 {$order->order_no} 邮件通知失败: {$e->getMessage()}");
            }
        }

        if ($order->contact && preg_match('/^1[3-9]\d{9}$/', $order->contact)) {
            try {
                SmsService::sendDeliverySms($order->contact, [
                    'order_no' => $order->order_no,
                    'product_name' => $product?->name ?? '-',
                ]);
            } catch (\Throwable $e) {
                Log::warning("订单 {$order->order_no} 短信通知失败: {$e->getMessage()}");
            }
        }

        $this->supplyCallback->sendForOrder($order);
    }
}
