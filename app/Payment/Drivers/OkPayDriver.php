<?php

namespace App\Payment\Drivers;

use App\Payment\AbstractPaymentDriver;
use App\Payment\Contracts\Payable;
use App\Payment\PaymentResult;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

/**
 * OKPay 虚拟货币支付驱动(https://api.okaypay.me/shop)。
 * 支持 USDT / TRX 链上收款,POST /payLink 创建订单,回调 form/JSON + MD5 验签。
 */
class OkPayDriver extends AbstractPaymentDriver
{
    /** OKPay 签名:参数(含 id,去 sign/空值)按 key 排序 → key=value&…&token=TOKEN → md5 大写 */
    protected function sign(array $params, string $token): string
    {
        ksort($params);
        $parts = [];
        foreach ($params as $key => $value) {
            $key = trim((string) $key);
            $value = trim((string) $value);
            if ($key === '' || $value === '' || strtolower($key) === 'sign') {
                continue;
            }
            $parts[] = $key.'='.$value;
        }

        return strtoupper(md5(implode('&', $parts).'&token='.trim($token)));
    }

    public function pay(Payable $order, array $config): PaymentResult
    {
        $gateway = rtrim((string) ($config['gateway_url'] ?? 'https://api.okaypay.me/shop'), '/');
        $merchantId = (string) ($config['merchant_id'] ?? '');
        $token = (string) ($config['merchant_token'] ?? '');
        $coin = strtoupper((string) ($config['coin'] ?? 'USDT'));
        $rate = (string) ($config['exchange_rate'] ?? 1);

        // exchange_rate 口径:1 法币 = N 币。分 → 元 → ×rate 得到 USDT/TRX。
        $yuan = bcdiv((string) $order->getPayableAmount(), '100', 2);
        $amount = is_numeric($rate) && bccomp($rate, '0', 8) === 1
            ? bcmul($yuan, $rate, 8)
            : $yuan;
        $amount = rtrim(rtrim($amount, '0'), '.');

        $params = [
            'unique_id' => $order->getPayableKey(),
            'amount' => $amount,
            'return_url' => $this->namedUrl('payment.return', ['code' => 'okpay']).'?order_no='.$order->getPayableKey(),
            'callback_url' => $this->namedUrl('payment.notify', ['channel' => 'okpay']),
            'coin' => $coin,
            'name' => $order->getPayableKey(),
            'id' => $merchantId,
        ];
        $params['sign'] = $this->sign($params, $token);

        $resp = Http::asForm()->timeout(20)->post($gateway.'/payLink', $params);
        $data = $resp->json();

        // 响应结构:{data: {order_id, pay_url}} 或 {data: [{...}]}
        $item = $data['data'] ?? [];
        if (isset($item[0]) && is_array($item[0])) {
            $item = $item[0];
        }
        $payUrl = is_string($item) ? $item : ($item['pay_url'] ?? null);
        if (! $payUrl) {
            throw new \RuntimeException('OKPay 下单失败: '.json_encode($data, JSON_UNESCAPED_UNICODE));
        }

        return PaymentResult::redirect((string) $payUrl);
    }

    public function verifyCallback(Request $request, array $config): ?array
    {
        $token = (string) ($config['merchant_token'] ?? '');
        $rate = (string) ($config['exchange_rate'] ?? 1);

        // 回调可能是 form 或 JSON,合并读取
        $raw = $request->post() ?: ($request->json() ? $request->json()->all() : []);
        if (empty($raw) || empty($raw['sign'])) {
            return null;
        }

        // 商户 ID 软校验(配置了才比对,参考 dujiao-next VerifyCallback)
        if (! empty($config['merchant_id']) && ! empty($raw['id'])
            && (string) $raw['id'] !== (string) $config['merchant_id']) {
            return null;
        }

        // 展开 data 子数组为 data[key] 扁平键(form 编码 data[order_id] 会被解析成嵌套数组)
        $payload = [];
        foreach ($raw as $key => $value) {
            if (is_array($value)) {
                foreach ($value as $subKey => $subValue) {
                    $payload["{$key}[{$subKey}]"] = $subValue;
                }
            } else {
                $payload[$key] = $value;
            }
        }

        // 验签:去掉 sign 后按 key 排序重签比对(参考实现接受字母排序签名)
        $expected = $this->sign($payload, $token);
        if (! hash_equals($expected, strtoupper((string) $raw['sign']))) {
            return null;
        }

        // 外层 status=success 且 data.status=1 才算支付成功
        $data = $raw['data'] ?? [];
        if (strtolower((string) ($raw['status'] ?? '')) !== 'success') {
            return null;
        }
        if ((string) ($data['status'] ?? '') !== '1') {
            return null;
        }

        // 回调 amount 是币数，按配置口径反算:币 ÷ (币/法币) × 100 = 分。
        $coinAmount = (string) ($data['amount'] ?? '');
        if (! is_numeric($coinAmount)) {
            return null;
        }
        $baseYuan = is_numeric($rate) && bccomp($rate, '0', 8) === 1
            ? bcdiv($coinAmount, $rate, 10)
            : $coinAmount;
        $fen = (int) bcadd(bcmul($baseYuan, '100', 10), '0.5', 0);

        return [
            'channel_order_no' => $data['order_id'] ?? null,
            'out_trade_no' => $data['unique_id'] ?? null,
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
                'required' => false,
                'default' => 'https://api.okaypay.me/shop',
                'help' => 'OKPay 网关,默认官方地址,一般无需修改。',
            ],
            'merchant_id' => ['label' => '商户ID', 'type' => 'text', 'required' => true],
            'merchant_token' => ['label' => '商户密钥(Merchant Token)', 'type' => 'secret', 'required' => true],
            'coin' => [
                'label' => '收款币种',
                'type' => 'select',
                'options' => ['USDT' => 'USDT', 'TRX' => 'TRX'],
                'required' => true,
                'default' => 'USDT',
            ],
            'exchange_rate' => [
                'label' => '汇率(1 法币 = ? 币)',
                'type' => 'number',
                'required' => true,
                'default' => '0.14',
                'help' => '法币兑 USDT/TRX 的汇率,如 1 元 = 0.14 USDT 填 0.14。',
            ],
            'display_name' => ['label' => '订单显示名称', 'type' => 'text', 'required' => false],
        ];
    }

    public function getInfo(): array
    {
        return ['name' => 'OKPay', 'icon' => 'okpay'];
    }

    public function getPayTypes(array $config): array
    {
        return ['usdt'];
    }

    public function getSupportedCurrencies(): array
    {
        return ['USDT', 'TRX'];
    }

    /** 核心验签凭据:商户 Token(不在历史默认键列表内,H-3 必须自声明) */
    public function getCredentialKeys(): array
    {
        return ['merchant_token'];
    }
}
