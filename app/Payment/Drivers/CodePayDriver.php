<?php

namespace App\Payment\Drivers;

use App\Payment\Contracts\Payable;
use App\Payment\Contracts\PaymentDriver;
use App\Payment\PaymentResult;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\URL;

class CodePayDriver implements PaymentDriver
{
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
     * 对参与签名的参数做字典序升序拼接后做 MD5。
     */
    protected function sign(array $params, string $key): string
    {
        $params = Arr::where($params, fn ($v, $k) => $k !== 'sign' && $k !== 'sign_type' && $v !== '' && $v !== null);
        ksort($params);

        $parts = [];
        foreach ($params as $k => $v) {
            $parts[] = $k . '=' . $v;
        }
        $query = implode('&', $parts);

        return md5($query . $key);
    }

    public function pay(Payable $order, array $config): PaymentResult
    {
        $pid = $config['pid'] ?? '';
        $key = $config['key'] ?? '';
        $apiUrl = rtrim($config['url'] ?? $config['api_url'] ?? '', '/');

        $params = [
            'pid' => $pid,
            'type' => $config['type'] ?? 'alipay',
            'out_trade_no' => $order->getPayableKey(),
            'notify_url' => $this->namedUrl('payment.notify', ['channel' => 'codepay']),
            'return_url' => $this->namedUrl('payment.return', ['code' => 'codepay']) . '?order_no=' . $order->getPayableKey(),
            'name' => $order->getPayableKey(),
            'money' => bcdiv((string) $order->getPayableAmount(), '100', 2), // 分→元
        ];

        $params['sign'] = $this->sign($params, $key);
        $params['sign_type'] = 'MD5';

        $url = $apiUrl . '/submit.php?' . http_build_query($params);

        return PaymentResult::redirect($url);
    }

    public function verifyCallback(Request $request, array $config): ?array
    {
        $key = $config['key'] ?? '';
        // 码支付回调可能走 GET query 或 POST body
        $data = array_merge($request->query(), $request->post());

        $tradeStatus = $data['trade_status'] ?? '';
        if ($tradeStatus !== 'TRADE_SUCCESS') {
            return null;
        }

        $expected = $this->sign($data, $key);
        $provided = $data['sign'] ?? '';

        if (!hash_equals($expected, (string) $provided)) {
            return null;
        }

        return [
            'channel_order_no' => $data['trade_no'] ?? null,
            'out_trade_no' => $data['out_trade_no'] ?? null,
            'amount' => (int) round(bcmul((string) ($data['money'] ?? 0), '100', 3)), // 元→分
            'raw' => $data,
        ];
    }

    public function getConfigFields(): array
    {
        return [
            'pid' => [
                'label' => '商户 ID(PID)',
                'type' => 'text',
                'required' => true,
            ],
            'key' => [
                'label' => '商户密钥(KEY)',
                'type' => 'text',
                'required' => true,
            ],
            'url' => [
                'label' => '支付网关地址',
                'type' => 'text',
                'required' => true,
                'placeholder' => '如 https://www.codepay.fateqq.com',
            ],
            'type' => [
                'label' => '支付方式',
                'type' => 'select',
                'options' => ['alipay' => '支付宝', 'wxpay' => '微信支付', 'qqpay' => 'QQ钱包'],
                'required' => true,
                'default' => 'alipay',
            ],
            'target_currency' => [
                'label' => '收款货币',
                'type' => 'text',
                'required' => false,
                'default' => 'CNY',
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
            'name' => '码支付',
            'icon' => '📋',
        ];
    }

    public function getSupportedCurrencies(): array
    {
        return ['CNY'];
    }
}
