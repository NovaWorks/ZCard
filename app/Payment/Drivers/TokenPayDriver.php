<?php

namespace App\Payment\Drivers;

use App\Payment\Contracts\Payable;
use App\Payment\Contracts\PaymentDriver;
use App\Payment\PaymentResult;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

/**
 * TokenPay 虚拟货币支付驱动(USDT)。
 * POST {gateway}/CreateOrder 创建订单(JSON),回调 JSON + MD5 验签。
 */
class TokenPayDriver implements PaymentDriver
{
    protected function namedUrl(string $name, array $params = []): string
    {
        return url(route($name, $params, false));
    }

    /** TokenPay 签名:参数(去 Signature/空值)排序 → key=value&… + notify_secret → md5 小写 */
    protected function sign(array $params, string $secret): string
    {
        $parts = [];
        foreach ($params as $key => $value) {
            $key = trim((string) $key);
            $value = trim((string) $value);
            if ($key === '' || $value === '' || strtolower($key) === 'signature') {
                continue;
            }
            $parts[$key] = $value;
        }
        ksort($parts);
        $query = implode('&', array_map(fn ($k, $v) => $k.'='.$v, array_keys($parts), $parts));

        return md5($query.trim($secret));
    }

    public function pay(Payable $order, array $config): PaymentResult
    {
        $gateway = rtrim((string) ($config['gateway_url'] ?? ''), '/');
        $secret = (string) ($config['notify_secret'] ?? '');
        $currency = strtoupper((string) ($config['currency'] ?? 'USDT'));
        $rate = (float) ($config['exchange_rate'] ?? 1);

        // 分 → 元 → (÷rate) USDT
        $yuan = bcdiv((string) $order->getPayableAmount(), '100', 2);
        $amount = $rate > 0 ? bcdiv($yuan, (string) $rate, 8) : $yuan;

        $payload = [
            'OutOrderId' => $order->getPayableKey(),
            'OrderUserKey' => $order->getPayableKey(),
            'ActualAmount' => rtrim(rtrim(number_format((float) $amount, 8, '.', ''), '0'), '.'),
            'Currency' => $currency,
            'NotifyUrl' => $this->namedUrl('payment.notify', ['channel' => 'tokenpay']),
            'RedirectUrl' => $this->namedUrl('payment.return', ['code' => 'tokenpay']).'?order_no='.$order->getPayableKey(),
        ];
        $payload['Signature'] = $this->sign($payload, $secret);

        $resp = Http::asJson()->timeout(20)->post($gateway.'/CreateOrder', $payload);
        $data = $resp->json();

        if (! ($data['success'] ?? false)) {
            throw new \RuntimeException('TokenPay 下单失败: '.json_encode($data, JSON_UNESCAPED_UNICODE));
        }

        // data 可能是支付链接,或 info 里有二维码/收款地址
        $payUrl = $data['data'] ?? null;
        if (is_string($payUrl) && $payUrl !== '') {
            return PaymentResult::redirect($payUrl);
        }
        $info = $data['info'] ?? [];
        $qrcode = $info['QrCodeBase64'] ?? null;
        if ($qrcode) {
            return PaymentResult::qrcode('data:image/png;base64,'.$qrcode);
        }
        $toAddress = $info['ToAddress'] ?? null;
        if ($toAddress) {
            return PaymentResult::qrcode('USDT:'.$toAddress.'?amount='.$amount);
        }
        if (! empty($info['PaymentUrl'])) {
            return PaymentResult::redirect((string) $info['PaymentUrl']);
        }

        throw new \RuntimeException('TokenPay 下单失败: 未返回支付信息');
    }

    public function verifyCallback(Request $request, array $config): ?array
    {
        $secret = (string) ($config['notify_secret'] ?? '');
        $rate = (float) ($config['exchange_rate'] ?? 1);

        $raw = $request->json() ? $request->json()->all() : [];
        if (empty($raw)) {
            return null;
        }

        $signature = (string) ($raw['Signature'] ?? $raw['signature'] ?? '');
        if ($signature === '') {
            return null;
        }

        // 验签:与下单签名一致(去 Signature/空值,排序拼接 + secret,md5)
        $expected = $this->sign($raw, $secret);
        if (! hash_equals($expected, strtolower($signature))) {
            return null;
        }

        // Status=1 为支付成功
        if ((int) ($raw['Status'] ?? $raw['status'] ?? 0) !== 1) {
            return null;
        }

        // 回调 ActualAmount 是 USDT → 反算回分:USDT × rate × 100
        $usdt = (float) ($raw['ActualAmount'] ?? $raw['actual_amount'] ?? 0);
        $fen = $rate > 0 ? (int) round($usdt * $rate * 100) : (int) round($usdt * 100);

        return [
            'channel_order_no' => $raw['Id'] ?? $raw['id'] ?? null,
            'out_trade_no' => $raw['OutOrderId'] ?? $raw['out_order_id'] ?? null,
            'amount' => $fen,
            'raw' => $raw,
        ];
    }

    public function getConfigFields(): array
    {
        return [
            'gateway_url' => [
                'label' => '网关地址',
                'type' => 'text',
                'required' => true,
                'help' => 'TokenPay 网关地址(不含 /CreateOrder)。',
            ],
            'notify_secret' => ['label' => '通知密钥(Notify Secret)', 'type' => 'secret', 'required' => true],
            'currency' => [
                'label' => '收款币种',
                'type' => 'text',
                'required' => false,
                'default' => 'USDT',
            ],
            'exchange_rate' => [
                'label' => '汇率(1 法币 = ? 币)',
                'type' => 'number',
                'required' => true,
                'default' => '0.14',
                'help' => '法币兑 USDT 的汇率,如 1 元 = 0.14 USDT 填 0.14。',
            ],
        ];
    }

    public function getInfo(): array
    {
        return ['name' => 'TokenPay', 'icon' => '₮'];
    }

    public function getPayTypes(array $config): array
    {
        return ['usdt'];
    }

    public function getSupportedCurrencies(): array
    {
        return ['USDT'];
    }
}
