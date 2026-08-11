<?php

namespace App\Payment\Drivers;

use App\Payment\AbstractPaymentDriver;
use App\Payment\Contracts\Payable;
use App\Payment\PaymentResult;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;

/**
 * 易支付(EPay / 彩虹易支付)驱动。
 *
 * 协议参考:NPanel-backend/pkg/payment/epay + acg-faka Epay 插件 + 彩虹易支付官方文档。
 *
 * 签名方式(可配置):
 * - MD5(V1,默认):ksort → key=value 用 & 拼接 → 末尾直接追加 key → md5 小写
 * - RSA/RSA2(V2 / SHA256WithRSA):ksort → key=value 用 & 拼接 → 商户私钥 SHA256 签名 → base64
 *
 * 下单方式:GET {url}/submit.php?{签名参数} 跳转。
 * 参考 acg-faka,每次下单都明确传递用户选中的 type,不把多种方式
 * 折叠为一个无 type 的聚合按钮。
 *
 * 回调:GET query 或 POST 传参,TRADE_SUCCESS/TRADE_FINISHED 为成功,返回 "success"。
 */
class EpayDriver extends AbstractPaymentDriver
{
    private const PAY_TYPES = [
        'alipay' => '支付宝',
        'wxpay' => '微信支付',
        'qqpay' => 'QQ 钱包',
        'bank' => '云闪付 / 网银',
        'jdpay' => '京东支付',
        'paypal' => 'PayPal',
    ];

    /**
     * 签名:按 sign_type 分流 MD5 / RSA / RSA2。
     *
     * MD5(V1):参数排序 → key=value& → 末尾追加商户 key → md5 小写
     * RSA(V2):参数排序 → key=value& → 商户私钥 SHA256 签名 → base64
     *
     * @param  array  $params  待签名参数(含 sign/sign_type 会被剔除)
     * @param  string  $secret  商户密钥(MD5)或 PKCS#8 私钥(RSA)
     */
    protected function sign(array $params, string $secret, string $signType = 'MD5'): string
    {
        // 剔除 sign / sign_type / 空值
        $params = Arr::where($params, fn ($v, $k) => $k !== 'sign' && $k !== 'sign_type' && $v !== '' && $v !== null);
        ksort($params);

        $parts = [];
        foreach ($params as $k => $v) {
            $parts[] = $k.'='.$v;
        }
        $query = implode('&', $parts);

        $algorithm = $this->normalizeSignType($signType);
        if ($algorithm === null) {
            throw new \RuntimeException('易支付签名方式不受支持');
        }

        if ($algorithm === 'RSA') {
            return $this->rsaSign($query, $secret);
        }

        return md5($query.$secret);
    }

    /**
     * RSA(SHA256WithRSA)签名:用商户私钥对拼接串做 SHA256 签名,base64 输出。
     */
    protected function rsaSign(string $data, string $privateKey): string
    {
        $key = $this->parsePrivateKey($privateKey);
        if ($key === false) {
            throw new \RuntimeException('易支付 RSA 私钥格式错误');
        }

        if (! openssl_sign($data, $signature, $key, OPENSSL_ALGO_SHA256)) {
            throw new \RuntimeException('易支付 RSA 签名失败');
        }

        return base64_encode($signature);
    }

    /**
     * RSA(SHA256WithRSA)验签:用平台公钥校验回调签名。
     * 注意方向与签名相反 —— 下单用商户私钥签,回调用平台公钥验。
     */
    protected function rsaVerify(string $data, string $publicKey, string $sign): bool
    {
        $key = $this->parsePublicKey($publicKey);
        if ($key === false) {
            return false;
        }

        // 某些 GET 回调未正确转义 base64 的 "+",PHP 会解析为空格,兼容恢复。
        $signature = base64_decode(str_replace(' ', '+', trim($sign)), true);
        if ($signature === false) {
            return false;
        }

        $result = openssl_verify($data, $signature, $key, OPENSSL_ALGO_SHA256);

        return $result === 1;
    }

    /** 兼容 PKCS#1/PKCS#8 私钥、完整 PEM、裸 Base64 与转义换行。 */
    private function parsePrivateKey(string $privateKey): \OpenSSLAsymmetricKey|false
    {
        foreach ($this->keyCandidates($privateKey, ['PRIVATE KEY', 'RSA PRIVATE KEY']) as $candidate) {
            $key = openssl_pkey_get_private($candidate);
            if ($key !== false) {
                return $key;
            }
        }

        return false;
    }

    /** 兼容 PKCS#1/X.509 SubjectPublicKeyInfo 公钥及常见文本形态。 */
    private function parsePublicKey(string $publicKey): \OpenSSLAsymmetricKey|false
    {
        foreach ($this->keyCandidates($publicKey, ['PUBLIC KEY', 'RSA PUBLIC KEY']) as $candidate) {
            $key = openssl_pkey_get_public($candidate);
            if ($key !== false) {
                return $key;
            }
        }

        return false;
    }

    /** @return list<string> */
    private function keyCandidates(string $rawKey, array $pemTypes): array
    {
        $normalized = trim(str_replace(
            ['\\r\\n', '\\n', '\\r', "\r\n", "\r"],
            ["\n", "\n", "\n", "\n", "\n"],
            $rawKey,
        ));
        if ($normalized === '') {
            return [];
        }
        if (str_contains($normalized, '-----BEGIN')) {
            return [$normalized];
        }

        $body = preg_replace('/\s+/', '', $normalized) ?? '';
        if ($body === '') {
            return [];
        }

        return array_map(
            fn (string $type) => "-----BEGIN {$type}-----\n"
                .wordwrap($body, 64, "\n", true)
                ."\n-----END {$type}-----",
            $pemTypes,
        );
    }

    private function normalizeSignType(string $signType): ?string
    {
        return match (strtoupper(trim($signType))) {
            'MD5' => 'MD5',
            'RSA', 'RSA2', 'SHA256WITHRSA' => 'RSA',
            default => null,
        };
    }

    public function pay(Payable $order, array $config): PaymentResult
    {
        $config = $this->validateConfig($config);
        $pid = $config['pid'];
        $key = $config['key'];
        $apiUrl = $config['url'];
        $signType = strtoupper(trim((string) ($config['sign_type'] ?? 'MD5')));
        if ($this->normalizeSignType($signType) === null) {
            throw new \RuntimeException('易支付签名方式不受支持');
        }

        // acg-faka 会把用户选中的支付 code 明确作为 type 提交。
        // ZCard 由 PaymentService 将本次选择放入瞬时配置,不写回数据库。
        $payType = trim((string) ($config['_selected_pay_type'] ?? ''));
        $types = $this->normalizePayTypes($config['type'] ?? []);
        // 兼容升级前未传 pay_type 的旧前端:回退到已启用方式的第一个。
        if ($payType === '' && $types !== []) {
            $payType = $types[0];
        }
        if ($payType === '' || ! in_array($payType, $types, true)) {
            throw new \RuntimeException('请选择具体的易支付方式');
        }

        $params = [
            'pid' => $pid,
            'out_trade_no' => $order->getPayableKey(),
            'notify_url' => $this->namedUrl('api.payments.callback', ['channel' => 'epay'], $config),
            'return_url' => $this->namedUrl('payment.return', ['code' => 'epay'], $config).'?order_no='.$order->getPayableKey(),
            'name' => $order->getPayableKey(),
            'money' => bcdiv((string) $order->getPayableAmount(), '100', 2), // 分→元
            'type' => $payType,
            'sitename' => (string) config('app.name', 'ZCard'),
        ];

        $params['sign'] = $this->sign($params, $key, $signType);
        $params['sign_type'] = $signType;

        $url = $apiUrl.'/submit.php?'.http_build_query($params);

        return PaymentResult::redirect($url);
    }

    public function verifyCallback(Request $request, array $config): ?array
    {
        $config = $this->normalizeConfig($config);
        $key = $config['key'] ?? '';
        // Illuminate Request::all() 同时兼容表单和 JSON,再合并 query 兼容 GET 通知。
        $data = array_merge($request->query(), $request->all());
        foreach ($data as $value) {
            if (! is_scalar($value) && $value !== null) {
                return $this->rejectCallback('payload_invalid', $data);
            }
        }

        // 验签算法必须以服务端已保存配置为准,回调参数不能自行切换算法。
        // 新配置固定 MD5;仅历史 RSA/RSA2 配置继续走兼容分支。
        $signType = strtoupper(trim((string) ($config['sign_type'] ?? 'MD5')));
        $algorithm = $this->normalizeSignType($signType);
        if ($algorithm === null) {
            return $this->rejectCallback('unsupported_sign_type', $data);
        }

        $tradeStatus = strtoupper(trim((string) ($data['trade_status'] ?? '')));
        if (! in_array($tradeStatus, ['TRADE_SUCCESS', 'TRADE_FINISHED'], true)) {
            return $this->rejectCallback('trade_status_not_success', $data);
        }

        // 校验回调归属,防止其他商户的合法回调注入当前渠道。
        $configuredPid = trim((string) ($config['pid'] ?? ''));
        $callbackPid = trim((string) ($data['pid'] ?? ''));
        if ($configuredPid === '' || ($callbackPid !== '' && ! hash_equals($configuredPid, $callbackPid))) {
            return $this->rejectCallback('pid_mismatch', $data);
        }

        $provided = (string) ($data['sign'] ?? '');
        if (trim($provided) === '') {
            return $this->rejectCallback('signature_missing', $data);
        }

        // 验签:MD5 用共享密钥(商户 key)重签比对;RSA 用平台公钥校验(方向相反)
        if ($algorithm === 'RSA') {
            $platformPublicKey = (string) ($config['platform_public_key'] ?? '');
            if (trim($platformPublicKey) === '') {
                return $this->rejectCallback('platform_public_key_missing', $data);
            }
            // 重现签名原文(与 sign() 内部一致)
            $params = Arr::where($data, fn ($v, $k) => $k !== 'sign' && $k !== 'sign_type' && $v !== '' && $v !== null);
            ksort($params);
            $query = implode('&', array_map(fn ($k, $v) => $k.'='.$v, array_keys($params), $params));
            if (! $this->rsaVerify($query, $platformPublicKey, $provided)) {
                return $this->rejectCallback('rsa_signature_invalid', $data);
            }
        } else {
            if ($key === '') {
                return $this->rejectCallback('merchant_key_missing', $data);
            }
            $expected = $this->sign($data, $key, $signType);
            if (! hash_equals($expected, strtolower($provided))) {
                return $this->rejectCallback('md5_signature_invalid', $data);
            }
        }

        $money = trim((string) ($data['money'] ?? ''));
        if ($money === '' || ! preg_match('/^\d+(?:\.\d{1,2})?$/D', $money)) {
            return $this->rejectCallback('amount_invalid', $data);
        }

        return [
            'channel_order_no' => $data['trade_no'] ?? null,
            'out_trade_no' => $data['out_trade_no'] ?? null,
            'amount' => (int) bcmul($money, '100', 0), // 元→分,避免浮点精度参与金额校验
            'raw' => $data,
        ];
    }

    private function rejectCallback(string $reason, array $data): null
    {
        Log::warning('易支付回调验证失败', [
            'reason' => $reason,
            'out_trade_no' => trim((string) ($data['out_trade_no'] ?? '')),
            'trade_no' => trim((string) ($data['trade_no'] ?? '')),
            'trade_status' => trim((string) ($data['trade_status'] ?? '')),
            'sign_type' => trim((string) ($data['sign_type'] ?? '')),
        ]);

        return null;
    }

    public function getConfigFields(): array
    {
        return [
            'url' => [
                'label' => '支付网关',
                'type' => 'text',
                'required' => true,
                'placeholder' => '如 https://pay.example.com',
                'help' => '只填易支付站点根地址;如果粘贴了 /submit.php,保存时会自动去除。',
            ],
            'pid' => [
                'label' => '商户 ID',
                'type' => 'text',
                'required' => true,
            ],
            'key' => [
                'label' => '商户密钥',
                'type' => 'password',
                'required' => true,
                'help' => '填写易支付商户后台中的 KEY;保存后不回显,留空表示保留原值。',
            ],
            'type' => [
                'label' => '前台支付方式',
                'type' => 'multiselect',
                'options' => self::PAY_TYPES,
                'required' => true,
                'default' => ['alipay', 'wxpay'],
                'help' => '前台会分别显示选中的方式,下单时会明确向易支付提交 type。',
            ],
            // 仅用于识别并维护历史 RSA/RSA2 配置;新配置不展示。
            'sign_type' => [
                'label' => '历史签名方式',
                'type' => 'select',
                'options' => ['RSA2' => 'RSA2 / SHA256WithRSA', 'RSA' => 'RSA / SHA256WithRSA'],
                'required' => true,
                'legacy' => true,
            ],
            'platform_public_key' => [
                'label' => '平台公钥(历史 RSA 配置)',
                'type' => 'textarea',
                'required' => true,
                'legacy' => true,
            ],
        ];
    }

    /**
     * 兼容历史 gateway_url/type 形态,并将用户粘贴的 submit.php 归一为网关根地址。
     */
    public function normalizeConfig(array $config): array
    {
        $url = trim((string) ($config['url'] ?? $config['gateway_url'] ?? ''));
        $url = preg_replace('~/+(?:submit|mapi)\.php/?$~i', '', $url) ?? $url;
        $config['url'] = rtrim($url, '/');
        unset($config['gateway_url']);
        $config['pid'] = trim((string) ($config['pid'] ?? ''));
        $config['type'] = $this->normalizePayTypes($config['type'] ?? ['alipay']);
        $config['sign_type'] = strtoupper(trim((string) ($config['sign_type'] ?? 'MD5'))) ?: 'MD5';

        return $config;
    }

    /** 返回已归一且可用的配置,下单与后台启用前共用。 */
    public function validateConfig(array $config): array
    {
        $config = $this->normalizeConfig($config);
        if ($config['url'] === '' || filter_var($config['url'], FILTER_VALIDATE_URL) === false
            || ! in_array(strtolower((string) parse_url($config['url'], PHP_URL_SCHEME)), ['http', 'https'], true)
            || parse_url($config['url'], PHP_URL_USER) !== null
            || parse_url($config['url'], PHP_URL_PASS) !== null
            || parse_url($config['url'], PHP_URL_QUERY) !== null
            || parse_url($config['url'], PHP_URL_FRAGMENT) !== null) {
            throw new \RuntimeException('请填写正确的易支付网关地址');
        }
        if ($config['pid'] === '') {
            throw new \RuntimeException('请填写易支付商户 ID');
        }
        if (trim((string) ($config['key'] ?? '')) === '') {
            throw new \RuntimeException('请填写易支付商户密钥');
        }
        if ($config['type'] === []) {
            throw new \RuntimeException('请至少启用一种易支付方式');
        }
        $algorithm = $this->normalizeSignType($config['sign_type']);
        if ($algorithm === null) {
            throw new \RuntimeException('易支付签名方式不受支持');
        }
        if ($algorithm === 'RSA' && trim((string) ($config['platform_public_key'] ?? '')) === '') {
            throw new \RuntimeException('历史 RSA 配置缺少平台公钥');
        }

        return $config;
    }

    /** @return list<string> */
    private function normalizePayTypes(mixed $types): array
    {
        if (is_string($types)) {
            $types = preg_split('/[,\s]+/', $types, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        }

        return array_values(array_unique(array_filter(
            array_map(fn ($type) => trim((string) $type), is_array($types) ? $types : []),
            fn (string $type) => array_key_exists($type, self::PAY_TYPES),
        )));
    }

    public function getInfo(): array
    {
        return [
            'name' => '易支付',
            'icon' => 'epay',
        ];
    }

    public function getPayTypes(array $config): array
    {
        $types = $this->normalizePayTypes($config['type'] ?? ['alipay', 'wxpay']);

        return $types === [] ? ['alipay'] : $types;
    }

    public function getSupportedCurrencies(): array
    {
        return ['CNY'];
    }
}
