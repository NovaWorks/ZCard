<?php

namespace App\Payment\Drivers;

use App\Models\Order;
use App\Payment\Contracts\PaymentDriver;
use App\Payment\PaymentResult;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\URL;

class PaypalDriver implements PaymentDriver
{
    /**
     * 根据 mode 选择 PayPal 的 base URL。
     */
    protected function baseUrl(array $config): string
    {
        return ($config['mode'] ?? 'live') === 'sandbox'
            ? 'https://api-m.sandbox.paypal.com'
            : 'https://api-m.paypal.com';
    }

    /**
     * 安全构建命名路由 URL；若路由尚未定义则回退到当前请求 URL。
     */
    protected function namedUrl(string $name, array $params = []): string
    {
        if (app('router')->has($name)) {
            return route($name, $params, false);
        }

        return URL::current();
    }

    /**
     * 获取 PayPal access token (client credentials)。
     */
    protected function accessToken(array $config): string
    {
        $tokenRes = Http::withBasicAuth(
            $config['client_id'] ?? '',
            $config['client_secret'] ?? ''
        )->asForm()->post($this->baseUrl($config) . '/v1/oauth2/token', [
            'grant_type' => 'client_credentials',
        ]);

        return (string) $tokenRes->json('access_token');
    }

    public function pay(Order $order, array $config): PaymentResult
    {
        $token = $this->accessToken($config);

        $returnUrl = $this->namedUrl('payment.return', ['code' => 'paypal']) . '?order_no=' . $order->order_no;
        $cancelUrl = $this->namedUrl('payment.cancel', ['code' => 'paypal']) . '?order_no=' . $order->order_no;

        $payload = [
            'intent' => 'CAPTURE',
            'purchase_units' => [
                [
                    'reference_id' => $order->order_no,
                    'amount' => [
                        'currency_code' => 'USD',
                        'value' => bcdiv((string) $order->amount, '100', 2), // 分→元
                    ],
                ],
            ],
            'application_context' => [
                'return_url' => $returnUrl,
                'cancel_url' => $cancelUrl,
            ],
        ];

        $res = Http::withToken($token)
            ->post($this->baseUrl($config) . '/v2/checkout/orders', $payload);

        $links = $res->json('links') ?? [];
        foreach ($links as $link) {
            if (($link['rel'] ?? null) === 'approve') {
                return PaymentResult::redirect($link['href']);
            }
        }

        // 兜底：若返回了自有 id，拼接 PayPal 授权页。
        $orderId = $res->json('id');
        if ($orderId) {
            $host = ($config['mode'] ?? 'live') === 'sandbox'
                ? 'https://www.sandbox.paypal.com'
                : 'https://www.paypal.com';

            return PaymentResult::redirect($host . '/checkoutnow?token=' . $orderId);
        }

        return PaymentResult::redirect('');
    }

    public function verifyCallback(Request $request, array $config): ?array
    {
        $token = $this->accessToken($config);
        $orderId = $request->input('token') ?: $request->input('orderID');

        if (!$orderId) {
            return null;
        }

        $res = Http::withToken($token)
            ->post($this->baseUrl($config) . '/v2/checkout/orders/' . $orderId . '/capture');

        if (!$res->successful()) {
            return null;
        }

        $data = $res->json() ?? [];
        $status = $data['status'] ?? null;
        if ($status !== 'COMPLETED') {
            return null;
        }

        $unit = $data['purchase_units'][0] ?? [];
        $amountStr = $unit['payments']['captures'][0]['amount']['value'] ?? null;

        return [
            'channel_order_no' => $data['id'] ?? null,
            'out_trade_no' => $unit['reference_id'] ?? null,
            'amount' => $amountStr !== null ? (int) round(bcmul((string) $amountStr, '100', 3)) : null, // 元→分
            'raw' => $data,
        ];
    }

    public function getConfigFields(): array
    {
        return [
            'client_id' => [
                'label' => 'Client ID',
                'type' => 'text',
                'required' => true,
            ],
            'client_secret' => [
                'label' => 'Client Secret',
                'type' => 'text',
                'required' => true,
            ],
            'mode' => [
                'label' => '运行模式',
                'type' => 'select',
                'options' => ['live' => '正式环境', 'sandbox' => '沙箱环境'],
                'required' => true,
                'default' => 'live',
            ],
        ];
    }

    public function getInfo(): array
    {
        return [
            'name' => 'PayPal',
            'icon' => '🅿️',
        ];
    }

    public function getSupportedCurrencies(): array
    {
        return ['USD', 'EUR', 'GBP']; // PayPal 支持的主流货币
    }
}
