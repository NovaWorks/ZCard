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
 * 协议参考:NPanel-backend/pkg/payment/epay + acg-faka Epay 插件 + 彩虹易支付官方文档。
 *
 * 签名方式(可配置):
 * - MD5(V1,默认):ksort → key=value 用 & 拼接 → 末尾直接追加 key → md5 小写
 * - RSA(V2 / SHA256WithRSA):ksort → key=value 用 & 拼接 → 商户私钥 SHA256 签名 → base64
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
 * 回调:GET query 或 POST 传参,trade_status==TRADE_SUCCESS 为成功,返回 "success"。
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
     * 签名:按 sign_type 分流 MD5 / RSA。
     *
     * MD5(V1):参数排序 → key=value& → 末尾追加商户 key → md5 小写
     * RSA(V2):参数排序 → key=value& → 商户私钥 SHA256 签名 → base64
     *
     * @param array $params 待签名参数(含 sign/sign_type 会被剔除)
     * @param string $secret 商户密钥(MD5)或 PKCS#8 私钥(RSA)
     */
    protected function sign(array $params, string $secret, string $signType = 'MD5'): string
    {
        // 剔除 sign / sign_type / 空值
        $params = Arr::where($params, fn ($v, $k) => $k !== 'sign' && $k !== 'sign_type' && $v !== '' && $v !== null);
        ksort($params);

        $parts = [];
        foreach ($params as $k => $v) {
            $parts[] = $k . '=' . $v;
        }
        $query = implode('&', $parts);

        // MD5:末尾直接追加商户 key
        if (strtoupper($signType) === 'RSA') {
            return $this->rsaSign($query, $secret);
        }

        return md5($query . $secret);
    }

    /**
     * RSA(SHA256WithRSA)签名:用商户私钥对拼接串做 SHA256 签名,base64 输出。
     */
    protected function rsaSign(string $data, string $privateKey): string
    {
        // 私钥可能是 PKCS#8 原文或带 PEM 头;统一处理
        $pem = $privateKey;
        if (! str_contains($pem, '-----BEGIN')) {
            $pem = "-----BEGIN RSA PRIVATE KEY-----\n"
                . wordwrap($privateKey, 64, "\n", true)
                . "\n-----END RSA PRIVATE KEY-----";
        }

        $key = openssl_pkey_get_private($pem);
        if ($key === false) {
            throw new \RuntimeException('易支付 RSA 私钥格式错误');
        }

        openssl_sign($data, $signature, $key, OPENSSL_ALGO_SHA256);
        return base64_encode($signature);
    }

    /**
     * RSA(SHA256WithRSA)验签:用平台公钥校验回调签名。
     * 注意方向与签名相反 —— 下单用商户私钥签,回调用平台公钥验。
     */
    protected function rsaVerify(string $data, string $publicKey, string $sign): bool
    {
        $pem = $publicKey;
        if (! str_contains($pem, '-----BEGIN')) {
            $pem = "-----BEGIN PUBLIC KEY-----\n"
                . wordwrap($publicKey, 64, "\n", true)
                . "\n-----END PUBLIC KEY-----";
        }

        $key = openssl_pkey_get_public($pem);
        if ($key === false) {
            return false;
        }

        $result = openssl_verify($data, base64_decode($sign), $key, OPENSSL_ALGO_SHA256);
        return $result === 1;
    }

    public function pay(Payable $order, array $config): PaymentResult
    {
        $pid = $config['pid'] ?? '';
        $key = $config['key'] ?? '';
        $apiUrl = rtrim($config['url'] ?? '', '/');
        $signType = strtoupper($config['sign_type'] ?? 'MD5');

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
            'notify_url' => $this->namedUrl('payment.notify', ['channel' => 'epay']),
            'return_url' => $this->namedUrl('payment.return', ['code' => 'epay']) . '?order_no=' . $order->getPayableKey(),
            'name' => $order->getPayableKey(),
            'money' => bcdiv((string) $order->getPayableAmount(), '100', 2), // 分→元
        ];
        // type 仅在勾选单个支付方式时传(否则走聚合收银台)
        if ($payType !== '') {
            $params['type'] = $payType;
        }

        $params['sign'] = $this->sign($params, $key, $signType);
        $params['sign_type'] = $signType;

        $url = $apiUrl . '/submit.php?' . http_build_query($params);

        return PaymentResult::redirect($url);
    }

    public function verifyCallback(Request $request, array $config): ?array
    {
        $key = $config['key'] ?? '';
        $signType = strtoupper($config['sign_type'] ?? 'MD5');
        // 易支付回调可能走 GET query 或 POST body,合并读取
        $data = array_merge($request->query(), $request->post());

        $tradeStatus = $data['trade_status'] ?? '';
        if ($tradeStatus !== 'TRADE_SUCCESS') {
            return null;
        }

        $provided = (string) ($data['sign'] ?? '');

        // 验签:MD5 用共享密钥(商户 key)重签比对;RSA 用平台公钥校验(方向相反)
        if ($signType === 'RSA') {
            $platformPublicKey = $config['platform_public_key'] ?? '';
            if ($platformPublicKey === '') {
                return null; // RSA 模式未配平台公钥 → 无法验签
            }
            // 重现签名原文(与 sign() 内部一致)
            $params = Arr::where($data, fn ($v, $k) => $k !== 'sign' && $k !== 'sign_type' && $v !== '' && $v !== null);
            ksort($params);
            $query = implode('&', array_map(fn ($k, $v) => $k . '=' . $v, array_keys($params), $params));
            if (! $this->rsaVerify($query, $platformPublicKey, $provided)) {
                return null;
            }
        } else {
            $expected = $this->sign($data, $key, $signType);
            if (! hash_equals($expected, $provided)) {
                return null;
            }
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
            'sign_type' => [
                'label' => '签名方式',
                'type' => 'select',
                'options' => [
                    'MD5' => 'MD5(V1 接口,传统易支付通用)',
                    'RSA' => 'RSA / SHA256WithRSA(V2 接口,新版易支付)',
                ],
                'required' => true,
                'default' => 'MD5',
                'help' => 'V1 易支付用 MD5(填商户密钥);V2 新版易支付用 RSA(填商户私钥)。不确定选 MD5。',
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
                'help' => 'MD5 方式:填商户密钥(KEY);RSA 方式:填 PKCS#8 商户私钥(不含 PEM 头尾的纯字符串亦可),用于下单签名。',
            ],
            'platform_public_key' => [
                'label' => '平台公钥(仅 RSA)',
                'type' => 'textarea',
                'required' => false,
                'help' => 'RSA 方式必填:易支付平台公钥,用于校验回调签名。MD5 方式留空。',
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
