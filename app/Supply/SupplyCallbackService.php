<?php

namespace App\Supply;

use App\Models\Order;
use App\Models\SupplyOrder;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/** ZCard 作为上游时，把异步履约结果按四头 HMAC 协议通知下游。 */
class SupplyCallbackService
{
    public function __construct(private readonly CallbackUrlGuard $guard) {}

    public function sendForOrder(Order $order): void
    {
        if ($order->source !== 'supply' || $order->delivery_status !== 'delivered') {
            return;
        }

        $supplyOrder = SupplyOrder::with('supplierAccount')
            ->where('order_id', $order->id)
            ->first();
        if (! $supplyOrder?->callback_url || $supplyOrder->callback_status === SupplyOrder::CALLBACK_SENT) {
            return;
        }
        if (! $this->guard->isAllowed($supplyOrder->callback_url)) {
            $supplyOrder->update(['callback_status' => SupplyOrder::CALLBACK_FAILED]);

            return;
        }

        $payload = [
            'supply_order_id' => $supplyOrder->id,
            'downstream_order_no' => $supplyOrder->downstream_order_no,
            'status' => 'delivered',
            'fulfillment' => [
                'type' => $order->fulfillment_type_snapshot,
                'status' => 'delivered',
                'cards' => $order->orderDeliveries()->pluck('card_content')->all(),
                'instructions' => $order->instructions_snapshot ?: null,
            ],
        ];
        $body = (string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $path = parse_url($supplyOrder->callback_url, PHP_URL_PATH) ?: '/';
        $timestamp = (string) time();
        $nonce = 'callback_'.bin2hex(random_bytes(12));
        $secret = $this->decryptSecret((string) $supplyOrder->supplierAccount->getRawOriginal('api_secret'));
        $signString = HmacSigner::buildSignString('POST', $path, $timestamp, $nonce, md5($body));

        try {
            $response = Http::withHeaders([
                'X-Supply-Key' => $supplyOrder->supplierAccount->api_key,
                'X-Supply-Timestamp' => $timestamp,
                'X-Supply-Nonce' => $nonce,
                'X-Supply-Signature' => HmacSigner::sign($secret, $signString),
            ])->timeout(15)->withBody($body, 'application/json')->post($supplyOrder->callback_url);

            $supplyOrder->update([
                'callback_status' => $response->successful()
                    ? SupplyOrder::CALLBACK_SENT
                    : SupplyOrder::CALLBACK_FAILED,
            ]);
            if (! $response->successful()) {
                Log::warning('供货订单异步回调失败', [
                    'supply_order_id' => $supplyOrder->id,
                    'status' => $response->status(),
                ]);
            }
        } catch (\Throwable $e) {
            $supplyOrder->update(['callback_status' => SupplyOrder::CALLBACK_FAILED]);
            Log::warning("供货订单 {$supplyOrder->id} 异步回调异常: {$e->getMessage()}");
        }
    }

    private function decryptSecret(string $raw): string
    {
        try {
            return Crypt::decryptString($raw);
        } catch (\Throwable) {
            return $raw;
        }
    }
}
