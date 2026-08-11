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
 * 下单方式:
 * - submit.php(跳转收银台):GET {url}/submit.php?{签名参数} 跳转
 *   type 不传 → 聚合收银台(用户自选支付宝/微信等)
 *   type 传单个 → 直接进对应支付通道
 *
 * 支付方式(type)支持多选:商户在后台勾选要支持的支付方式,
 * - 勾选多个 → 不传 type,用户在收银台自选
 * - 勾选单个 → 直接传该 type
 *
 * 回调:GET query 或 POST 传参,TRADE_SUCCESS/TRADE_FINISHED 为成功,返回 "success"。
 */
class EpayDriver extends AbstractPaymentDriver
{
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
        $pid = $config['pid'] ?? '';
        $key = $config['key'] ?? '';
        $apiUrl = rtrim($config['url'] ?? '', '/');
        $signType = strtoupper(trim((string) ($config['sign_type'] ?? 'MD5')));
        if ($this->normalizeSignType($signType) === null) {
            throw new \RuntimeException('易支付签名方式不受支持');
        }

        // 支付方式:多选配置,下单时按勾选数量决定是否传 type
        // - 勾选多个或空 → 不传 type,跳聚合收银台让用户自选
        // - 只勾选 1 个 → 传该 type,直接进对应通道
        $types = $config['type'] ?? [];
        if (is_string($types)) {
            $types = array_filter(array_map('trim', explode(',', $types)));
        }
        $types = array_values(array_filter((array) $types, fn ($v) => $v !== ''));
        $payType = count($types) === 1 ? $types[0] : '';

        $params = [
            'pid' => $pid,
            'out_trade_no' => $order->getPayableKey(),
            'notify_url' => $this->namedUrl('payment.notify', ['channel' => 'epay'], $config),
            'return_url' => $this->namedUrl('payment.return', ['code' => 'epay'], $config).'?order_no='.$order->getPayableKey(),
            'name' => $order->getPayableKey(),
            'money' => bcdiv((string) $order->getPayableAmount(), '100', 2), // 分→元
        ];
        // type 仅在勾选单个支付方式时传(否则走聚合收银台)
        if ($payType !== '') {
            $params['type'] = $payType;
        }

        $params['sign'] = $this->sign($params, $key, $signType);
        $params['sign_type'] = $signType;

        $url = $apiUrl.'/submit.php?'.http_build_query($params);

        return PaymentResult::redirect($url);
    }

    public function verifyCallback(Request $request, array $config): ?array
    {
        $key = $config['key'] ?? '';
        // 易支付回调可能走 GET query 或 POST body,合并读取
        $data = array_merge($request->query(), $request->post());

        // 验签算法:优先用回调自带的 sign_type 字段(易支付 889 等平台回调默认 RSA),
        // 其次回退到通道配置。若只按配置判断,回调 sign_type 与配置不一致时会用错算法 → 验签恒失败。
        $callbackSignType = strtoupper(trim((string) ($data['sign_type'] ?? '')));
        $signType = $callbackSignType !== ''
            ? $callbackSignType
            : strtoupper(trim((string) ($config['sign_type'] ?? 'MD5')));
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
        if ($configuredPid === '' || $callbackPid === '' || ! hash_equals($configuredPid, $callbackPid)) {
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
            if (! hash_equals($expected, $provided)) {
                return $this->rejectCallback('md5_signature_invalid', $data);
            }
        }

        return [
            'channel_order_no' => $data['trade_no'] ?? null,
            'out_trade_no' => $data['out_trade_no'] ?? null,
            'amount' => (int) round(bcmul((string) ($data['money'] ?? 0), '100', 3)), // 元→分
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
            'sign_type' => [
                'label' => '签名方式',
                'type' => 'select',
                'options' => [
                    'MD5' => 'MD5(V1 接口,传统易支付)',
                    'RSA2' => 'RSA2 / SHA256WithRSA(V2 接口,推荐)',
                    'RSA' => 'RSA / SHA256WithRSA(V2 兼容值)',
                ],
                'required' => true,
                'default' => 'MD5',
                'help' => '按平台要求选择。RSA 与 RSA2 均使用 SHA256WithRSA;支持常见 PEM、裸 Base64 和转义换行密钥。',
            ],
            'pid' => [
                'label' => '商户 ID(PID)',
                'type' => 'text',
                'required' => true,
            ],
            'key' => [
                'label' => '商户密钥 / 私钥',
                'type' => 'textarea',
                'required' => true,
                'help' => 'MD5:填商户密钥。RSA/RSA2:填 PKCS#1 或 PKCS#8 商户私钥,可带 PEM 头尾或仅填 Base64 主体。',
            ],
            'platform_public_key' => [
                'label' => '平台公钥(仅 RSA/RSA2)',
                'type' => 'textarea',
                'required' => false,
                'help' => 'RSA/RSA2 必填。支持 PKCS#1 或 X.509 公钥、完整 PEM、裸 Base64 和转义换行格式;MD5 留空。',
            ],
            'url' => [
                'label' => '易支付网关地址',
                'type' => 'text',
                'required' => true,
                'placeholder' => '如 https://pay.example.com',
            ],
            'type' => [
                'label' => '支持的支付方式(多选)',
                'type' => 'multiselect',
                'options' => [
                    'alipay' => '支付宝',
                    'wxpay' => '微信支付',
                    'qqpay' => 'QQ 钱包',
                    'bank' => '云闪付 / 网银',
                    'jdpay' => '京东支付',
                    'paypal' => 'PayPal',
                ],
                'required' => false,
                'default' => ['alipay', 'wxpay'],
                'help' => '勾选要支持的支付方式(可多选)。勾选多个时,用户在易支付收银台自选;只勾选一个则直接进入该支付通道。具体可用方式以易支付商户后台开通为准。',
            ],
            'notify_domain' => [
                'label' => '回调域名(可选)',
                'type' => 'text',
                'required' => false,
                'placeholder' => '如 https://kmigo.com',
                'help' => '回调地址默认用当前运行域名自动生成(部署到哪个域名就用哪个)。仅当回调入口与站点域名不一致(如走 CDN/独立公网入口)时才需填写。',
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
            'icon' => 'epay',
        ];
    }

    public function getPayTypes(array $config): array
    {
        // 聚合收银台:支持多种支付方式(后台 config.type 多选,默认 alipay+wxpay)
        $types = $config['type'] ?? ['alipay', 'wxpay'];
        if (is_string($types)) {
            $types = array_filter(array_map('trim', explode(',', $types)));
        }

        return array_values(array_filter((array) $types, fn ($v) => $v !== ''));
    }

    public function getSupportedCurrencies(): array
    {
        return ['CNY'];
    }
}
