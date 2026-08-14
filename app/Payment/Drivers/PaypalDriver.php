<?php

namespace App\Payment\Drivers;

use App\Payment\AbstractPaymentDriver;
use App\Payment\Contracts\Payable;
use App\Payment\PaymentResult;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class PaypalDriver extends AbstractPaymentDriver
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
     * 获取 PayPal access token (client credentials)。
     * 低危(M-11):缓存 token(官方有效期约 9 小时)——匿名回调端点会触发
     * accessToken() 出站请求,不缓存时每个请求都向 PayPal 换一次 token,
     * 可被滥用打满商户 API 速率配额,导致真实买家 capture 失败。
     */
    protected function accessToken(array $config): string
    {
        $cacheKey = 'paypal_token:'.hash('sha256', ($config['client_id'] ?? '').'|'.$this->baseUrl($config));
        $cached = Cache::get($cacheKey);
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        $tokenRes = Http::withBasicAuth(
            $config['client_id'] ?? '',
            $config['client_secret'] ?? ''
        )->asForm()->post($this->baseUrl($config).'/v1/oauth2/token', [
            'grant_type' => 'client_credentials',
        ]);

        $token = (string) $tokenRes->json('access_token');
        $expiresIn = (int) ($tokenRes->json('expires_in') ?? 0);
        if ($token !== '' && $expiresIn > 300) {
            // 提前 5 分钟失效,避免边界竞态
            Cache::put($cacheKey, $token, $expiresIn - 300);
        }

        return $token;
    }

    public function pay(Payable $order, array $config): PaymentResult
    {
        $token = $this->accessToken($config);

        $returnUrl = $this->namedUrl('payment.return', ['code' => 'paypal']).'?order_no='.$order->getPayableKey();
        $cancelUrl = $this->namedUrl('payment.cancel', ['code' => 'paypal']).'?order_no='.$order->getPayableKey();

        $payload = [
            'intent' => 'CAPTURE',
            'purchase_units' => [
                [
                    'reference_id' => $order->getPayableKey(),
                    'amount' => [
                        'currency_code' => 'USD',
                        'value' => bcdiv((string) $order->getPayableAmount(), '100', 2), // 分→元
                    ],
                ],
            ],
            'application_context' => [
                'return_url' => $returnUrl,
                'cancel_url' => $cancelUrl,
            ],
        ];

        $res = Http::withToken($token)
            ->post($this->baseUrl($config).'/v2/checkout/orders', $payload);

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

            return PaymentResult::redirect($host.'/checkoutnow?token='.$orderId);
        }

        return PaymentResult::redirect('');
    }

    public function verifyCallback(Request $request, array $config): ?array
    {
        $orderId = (string) ($request->input('token') ?: $request->input('orderID') ?: '');

        // 低危(M-11):单号格式白名单——匿名端点把该值拼进 PayPal API URL,
        // 无格式约束时任意输入都会触发一次出站请求(速率配额放大器)
        if ($orderId === '' || ! preg_match('/^[A-Z0-9]{5,30}$/', $orderId)) {
            return null;
        }

        $token = $this->accessToken($config);

        $res = Http::withToken($token)
            ->post($this->baseUrl($config).'/v2/checkout/orders/'.$orderId.'/capture');

        if (! $res->successful()) {
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
            'name' => 'PayPal',
            'icon' => 'paypal',
        ];
    }

    public function getPayTypes(array $config): array
    {
        return ['paypal'];
    }

    public function getSupportedCurrencies(): array
    {
        return ['USD', 'EUR', 'GBP']; // PayPal 支持的主流货币
    }
}
