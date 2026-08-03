<?php

namespace App\Payment\Drivers;

use App\Payment\Contracts\Payable;
use App\Payment\Contracts\PaymentDriver;
use App\Payment\PaymentResult;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\URL;

/**
 * 易支付(EPay / 彩虹易支付)驱动。
 *
 * 协议参考:NPanel-backend/pkg/payment/epay + acg-faka Epay 插件。
 * - 下单:GET {url}/submit.php?{签名参数} 跳转到收银台
 * - 签名:ksort → key=value 用 & 拼接 → 末尾直接追加 key → md5 小写
 * - 回调:GET query 传参,trade_status==TRADE_SUCCESS 为成功,返回 "success"
 */
class EpayDriver implements PaymentDriver
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
     * 易支付 MD5 签名:
     * 1. 剔除 sign / sign_type / 空值
     * 2. key 字典序升序排序
     * 3. 用 & 拼成 a=1&b=2(value 不做 URL 编码)
     * 4. 末尾直接追加商户 key(无分隔符)
     * 5. md5 取 32 位小写
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
        $apiUrl = rtrim($config['url'] ?? '', '/');

        $params = [
            'pid' => $pid,
            'type' => $config['type'] ?? 'alipay',
            'out_trade_no' => $order->getPayableKey(),
            'notify_url' => $this->namedUrl('payment.notify', ['channel' => 'epay']),
            'return_url' => $this->namedUrl('payment.return', ['code' => 'epay']) . '?order_no=' . $order->getPayableKey(),
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
        // 易支付回调可能走 GET query 或 POST body,合并读取
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
                'label' => '易支付网关地址',
                'type' => 'text',
                'required' => true,
                'placeholder' => '如 https://pay.example.com',
            ],
            'type' => [
                'label' => '默认支付方式',
                'type' => 'select',
                'options' => ['alipay' => '支付宝', 'wxpay' => '微信支付', 'qqpay' => 'QQ钱包', 'bank' => '网银', 'jdpay' => '京东', 'paypal' => 'PayPal'],
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
            'name' => '易支付',
            'icon' => '🔗',
        ];
    }

    public function getSupportedCurrencies(): array
    {
        return ['CNY'];
    }
}
