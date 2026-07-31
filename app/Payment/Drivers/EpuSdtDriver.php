<?php

namespace App\Payment\Drivers;

use App\Models\Order;
use App\Payment\Contracts\PaymentDriver;
use App\Payment\PaymentResult;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\URL;

/**
 * EpuSdt(USDT 加密货币支付网关)驱动。
 *
 * 参考:https://github.com/GMWalletApp/epusdt
 * - 下单:POST {api_url}/payments/gmpay/v1/order/create-transaction (HMAC-SHA256 签名)
 * - 回调:epusdt 主动 POST notify_url,JSON 格式,HMAC-SHA256 验签
 * - 金额:法币(元),epusdt 内部转换为 USDT
 */
class EpuSdtDriver implements PaymentDriver
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
     * GMPay HMAC-SHA256 签名:
     * 1. 排除 signature 字段
     * 2. 非空参数按 key ASCII 字典序排序
     * 3. 用 & 拼接成 key=value
     * 4. 用 secret_key 做 HMAC-SHA256
     * 5. 64 位小写十六进制
     */
    protected function sign(array $params, string $secretKey): string
    {
        unset($params['signature']);
        $params = array_filter($params, fn ($v) => $v !== '' && $v !== null);
        ksort($params, SORT_STRING);

        $pairs = [];
        foreach ($params as $k => $v) {
            $pairs[] = $k . '=' . $v;
        }

        return hash_hmac('sha256', implode('&', $pairs), $secretKey);
    }

    public function pay(Order $order, array $config): PaymentResult
    {
        $apiUrl = rtrim($config['api_url'] ?? '', '/');
        $pid = $config['pid'] ?? '';
        $secretKey = $config['secret_key'] ?? '';
        $currency = $config['currency'] ?? 'cny';

        $notifyUrl = $this->namedUrl('payment.notify', ['channel' => 'epusdt']);
        $redirectUrl = $this->namedUrl('payment.return', ['code' => 'epusdt']) . '?order_no=' . $order->order_no;

        $params = [
            'pid' => (string) $pid,
            'order_id' => $order->order_no,
            'currency' => $currency,
            'amount' => bcdiv((string) $order->amount, '100', 2), // 分→元
            'notify_url' => $notifyUrl,
            'redirect_url' => $redirectUrl,
            'name' => $order->order_no,
        ];

        $params['signature'] = $this->sign($params, $secretKey);

        $response = Http::timeout(15)->post($apiUrl . '/payments/gmpay/v1/order/create-transaction', $params);

        if (! $response->ok()) {
            throw new \RuntimeException('EpuSdt 下单失败: HTTP ' . $response->status());
        }

        $data = $response->json();
        if (($data['status_code'] ?? 0) !== 200) {
            throw new \RuntimeException('EpuSdt 下单失败: ' . ($data['message'] ?? '未知错误'));
        }

        $paymentUrl = $data['data']['payment_url'] ?? null;
        if (! $paymentUrl) {
            throw new \RuntimeException('EpuSdt 未返回支付链接');
        }

        return PaymentResult::redirect($paymentUrl);
    }

    public function verifyCallback(Request $request, array $config): ?array
    {
        $secretKey = $config['secret_key'] ?? '';
        $data = $request->all();

        // 状态必须是 2(支付成功)
        if ((int) ($data['status'] ?? 0) !== 2) {
            return null;
        }

        // HMAC-SHA256 验签
        $expected = $this->sign($data, $secretKey);
        $provided = $data['signature'] ?? '';

        if (! hash_equals($expected, (string) $provided)) {
            return null;
        }

        // amount 是法币金额(元),转回分
        $amountYuan = (float) ($data['amount'] ?? 0);

        return [
            'channel_order_no' => $data['trade_id'] ?? null,
            'out_trade_no' => $data['order_id'] ?? null,
            'amount' => (int) round($amountYuan * 100), // 元→分
            'raw' => $data,
        ];
    }

    public function getConfigFields(): array
    {
        return [
            'api_url' => [
                'label' => 'EpuSdt 服务地址',
                'type' => 'text',
                'required' => true,
                'placeholder' => '如 https://pay.example.com',
            ],
            'pid' => [
                'label' => '商户 PID',
                'type' => 'text',
                'required' => true,
                'placeholder' => '如 1000',
            ],
            'secret_key' => [
                'label' => '商户密钥(Secret Key)',
                'type' => 'text',
                'required' => true,
            ],
            'currency' => [
                'label' => '法币币种',
                'type' => 'select',
                'options' => ['cny' => '人民币(CNY)', 'usd' => '美元(USD)'],
                'required' => true,
                'default' => 'cny',
            ],
        ];
    }

    public function getInfo(): array
    {
        return [
            'name' => 'EpuSdt(USDT)',
            'icon' => '₮',
        ];
    }

    public function getSupportedCurrencies(): array
    {
        return ['CNY', 'USD'];
    }
}
