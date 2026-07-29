<?php

namespace App\Payment\Drivers;

use App\Models\Order;
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
        $params = Arr::where($params, fn ($v, $k) => $k !== 'sign' && $v !== '' && $v !== null);
        ksort($params);

        $parts = [];
        foreach ($params as $k => $v) {
            $parts[] = $k . '=' . $v;
        }
        $query = implode('&', $parts);

        return md5($query . $key);
    }

    public function pay(Order $order, array $config): PaymentResult
    {
        $pid = $config['pid'] ?? '';
        $key = $config['key'] ?? '';
        $apiUrl = rtrim($config['api_url'] ?? '', '/');

        $params = [
            'pid' => $pid,
            'type' => 'alipay',
            'out_trade_no' => $order->order_no,
            'notify_url' => $this->namedUrl('payment.notify', ['code' => 'codepay']),
            'return_url' => $this->namedUrl('payment.return', ['code' => 'codepay']),
            'name' => $order->order_no,
            'money' => (string) $order->amount,
        ];

        $params['sign'] = $this->sign($params, $key);
        $params['sign_type'] = 'MD5';

        $url = $apiUrl . '/submit.php?' . http_build_query($params);

        return PaymentResult::redirect($url);
    }

    public function verifyCallback(Request $request, array $config): ?array
    {
        $key = $config['key'] ?? '';
        $data = $request->all();

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
            'amount' => $data['money'] ?? null,
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
            'api_url' => [
                'label' => '接口地址(如 https://www.codepay.fateqq.com)',
                'type' => 'text',
                'required' => true,
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
}
