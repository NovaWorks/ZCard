<?php

namespace App\Payment\Drivers;

use App\Payment\Contracts\Payable;
use App\Payment\AbstractPaymentDriver;
use App\Payment\PaymentResult;
use Illuminate\Http\Request;
use Stripe\Checkout\Session;
use Stripe\Stripe;
use Stripe\Webhook;

class StripeDriver extends AbstractPaymentDriver
{
        public function pay(Payable $order, array $config): PaymentResult
    {
        Stripe::setApiKey($config['secret_key'] ?? '');

        $session = Session::create([
            'payment_method_types' => ['card'],
            'line_items' => [[
                'price_data' => [
                    'currency' => strtolower($config['currency'] ?? 'usd'),
                    'product_data' => [
                        'name' => $order->getPayableKey(),
                    ],
                    'unit_amount' => (int) $order->getPayableAmount(), // Stripe 用分,order->amount 已是分
                ],
                'quantity' => 1,
            ]],
            'mode' => 'payment',
            'client_reference_id' => $order->getPayableKey(),
            'success_url' => $this->namedUrl('payment.return', ['code' => 'stripe']).'?order_no='.$order->getPayableKey().'&session_id={CHECKOUT_SESSION_ID}',
            'cancel_url' => $this->namedUrl('payment.cancel', ['code' => 'stripe']).'?order_no='.$order->getPayableKey(),
        ]);

        return PaymentResult::redirect($session->url);
    }

    public function verifyCallback(Request $request, array $config): ?array
    {
        $signature = $request->header('Stripe-Signature') ?: '';
        $payload = $request->getContent();

        try {
            $event = Webhook::constructEvent(
                $payload,
                $signature,
                $config['webhook_secret'] ?? ''
            );
        } catch (\Throwable $e) {
            return null;
        }

        if (($event->type ?? null) !== 'checkout.session.completed') {
            return null;
        }

        $session = $event->data->object ?? null;
        if (! $session) {
            return null;
        }

        $amount = isset($session->amount_total) ? (int) $session->amount_total : null; // Stripe 已是分

        return [
            'channel_order_no' => $session->payment_intent ?? $session->id,
            'out_trade_no' => $session->client_reference_id ?? null,
            'amount' => $amount,
            'raw' => $session->toArray(),
        ];
    }

    public function getConfigFields(): array
    {
        return [
            'secret_key' => [
                'label' => 'Secret Key (sk_live_... 或 sk_test_...)',
                'type' => 'text',
                'required' => true,
            ],
            'webhook_secret' => [
                'label' => 'Webhook Signing Secret (whsec_...)',
                'type' => 'text',
                'required' => true,
            ],
            'target_currency' => [
                'label' => '收款货币',
                'type' => 'text',
                'required' => false,
                'default' => 'USD',
            ],
            'exchange_rate' => [
                'label' => '汇率(基础货币→收款货币)',
                'type' => 'text',
                'required' => false,
                'default' => '1',
            ],
        ];
    }

    public function getInfo(): array
    {
        return [
            'name' => 'Stripe',
            'icon' => 'stripe',
        ];
    }

    public function getPayTypes(array $config): array
    {
        return ['stripe'];
    }

    public function getSupportedCurrencies(): array
    {
        return ['USD', 'EUR', 'GBP', 'CNY', 'JPY'];
    }
}
