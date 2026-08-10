<?php

namespace App\Payment\Drivers;

use App\Payment\Contracts\Payable;
use App\Payment\Contracts\PaymentDriver;
use App\Payment\PaymentResult;
use Illuminate\Http\Request;
use Yansongda\Pay\Pay;

class AlipayDriver implements PaymentDriver
{
    /**
     * 构建传给 yansongda/laravel-pay v3 的配置数组。
     *
     * 注意:yansongda v3 支付宝强制「证书模式」,必须提供应用公钥证书与支付宝根证书
     * (app_public_cert_path / alipay_root_cert_path,或对应 SN),
     * 否则报「缺少支付宝配置 -- [app_public_cert_path]」。
     * 证书字段兼容两种填法:服务器文件路径(PEM) 或 直接粘贴 PEM 内容(自动写入 storage/app/certs)。
     */
    protected function buildConfig(array $config): array
    {
        $mode = ($config['mode'] ?? 'normal') === 'sandbox'
            ? Pay::MODE_SANDBOX
            : Pay::MODE_NORMAL;

        return [
            'alipay' => [
                'default' => [
                    'app_id' => $config['app_id'] ?? '',
                    'app_secret_cert' => $config['private_key'] ?? '',
                    // 两种验签方式二选一:
                    // A. SN 直填(推荐,开放平台「密钥管理」页可复制,无需证书文件)
                    'app_public_cert_sn' => $config['app_public_cert_sn'] ?? null,
                    'alipay_root_cert_sn' => $config['alipay_root_cert_sn'] ?? null,
                    // B. 证书路径或 PEM 内容(自动落盘 storage/app/certs)
                    'app_public_cert_path' => $this->resolveCertPath($config['app_public_cert_path'] ?? null, 'app'),
                    'alipay_root_cert_path' => $this->resolveCertPath($config['alipay_root_cert_path'] ?? null, 'root'),
                    'mode' => $mode,
                ],
            ],
        ];
    }

    /** 证书字段兼容「路径」与「PEM 内容」两种填法 */
    private function resolveCertPath(?string $value, string $name): ?string
    {
        if (empty($value)) {
            return null;
        }
        if (! str_contains($value, '-----BEGIN')) {
            return $value;
        }
        $dir = storage_path('app/certs');
        if (! is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        $file = $dir.'/alipay_'.$name.'_'.md5($value).'.crt';
        if (! file_exists($file)) {
            file_put_contents($file, $value);
        }

        return $file;
    }

    public function pay(Payable $order, array $config): PaymentResult
    {
        Pay::config($this->buildConfig($config));

        $payType = $config['pay_type'] ?? 'web';
        $params = [
            'out_trade_no' => $order->getPayableKey(),
            'total_amount' => bcdiv((string) $order->getPayableAmount(), '100', 2),
            'subject' => $order->getPayableKey(),
        ];

        return match ($payType) {
            'h5' => $this->handleResponse(Pay::alipay()->h5(array_merge($params, [
                'quit_url' => rtrim(config('app.url'), '/').'/pay/result?order_no='.$order->getPayableKey(),
            ]))),
            'scan' => $this->scanPay($order, $params),
            'pos' => $this->handleResponse(Pay::alipay()->pos($params)),
            default => $this->handleResponse(Pay::alipay()->web($params)),
        };
    }

    /** 当面付扫码:返回二维码内容(code_url) */
    private function scanPay(Payable $order, array $params): PaymentResult
    {
        $result = Pay::alipay()->scan($params);
        $codeUrl = is_array($result)
            ? ($result['code_url'] ?? null)
            : (property_exists($result, 'code_url') ? $result->code_url : null);

        if (! $codeUrl && method_exists($result, 'get')) {
            $codeUrl = $result->get('code_url');
        }

        return PaymentResult::qrcode((string) $codeUrl);
    }

    /** 统一处理 Response/HTML 结果:302 跳转 → redirect,其余按 HTML 表单 */
    private function handleResponse($result): PaymentResult
    {
        if (method_exists($result, 'getHeader')) {
            $location = $result->getHeader('Location')[0] ?? null;
            if ($location) {
                return PaymentResult::redirect((string) $location);
            }
        }

        $body = method_exists($result, 'getBody') ? (string) $result->getBody() : (string) $result;

        return PaymentResult::form($body);
    }

    public function verifyCallback(Request $request, array $config): ?array
    {
        Pay::config($this->buildConfig($config));

        try {
            $result = Pay::alipay()->callback($request->all());
        } catch (\Throwable $e) {
            return null;
        }

        $data = method_exists($result, 'all') ? $result->all() : (array) $result;

        $tradeStatus = $data['trade_status'] ?? null;
        if (! in_array($tradeStatus, ['TRADE_SUCCESS', 'TRADE_FINISHED'], true)) {
            return null;
        }

        return [
            'channel_order_no' => $data['trade_no'] ?? null,
            'out_trade_no' => $data['out_trade_no'] ?? null,
            'amount' => (int) round(bcmul((string) ($data['total_amount'] ?? 0), '100', 3)),
            'raw' => $data,
        ];
    }

    public function getConfigFields(): array
    {
        return [
            'app_id' => [
                'label' => '应用 APPID',
                'type' => 'text',
                'required' => true,
            ],
            'private_key' => [
                'label' => '应用私钥(应用密钥/私钥 PEM 内容)',
                'type' => 'textarea',
                'required' => true,
            ],
            'app_public_cert_sn' => [
                'label' => '应用公钥证书 SN(推荐,与证书二选一)',
                'type' => 'text',
                'required' => false,
                'help' => '开放平台 → 密钥管理 → 查看应用公钥证书 → 复制「SN」字段;填了 SN 则无需填下方证书',
            ],
            'alipay_root_cert_sn' => [
                'label' => '支付宝根证书 SN(推荐,与证书二选一)',
                'type' => 'text',
                'required' => false,
                'help' => '开放平台 → 密钥管理 → 查看支付宝根证书 → 复制「SN」字段',
            ],
            'app_public_cert_path' => [
                'label' => '应用公钥证书(路径或 PEM 内容)',
                'type' => 'textarea',
                'required' => false,
                'help' => '未填 SN 时必填。开放平台「密钥管理」下载 appCertPublicKey.crt;可填服务器文件路径,或直接粘贴证书内容(-----BEGIN CERTIFICATE----- 开头)',
            ],
            'alipay_root_cert_path' => [
                'label' => '支付宝根证书(路径或 PEM 内容)',
                'type' => 'textarea',
                'required' => false,
                'help' => '未填 SN 时必填。开放平台下载 alipayRootCert.crt;同上可填路径或粘贴内容',
            ],
            'pay_type' => [
                'label' => '支付方式',
                'type' => 'select',
                'options' => [
                    'web' => '电脑网站支付(PC 页面)',
                    'h5' => '手机网站支付(H5)',
                    'scan' => '当面付扫码(生成二维码)',
                    'pos' => '刷卡支付(付款码)',
                ],
                'required' => true,
                'default' => 'web',
            ],
            'mode' => [
                'label' => '运行模式',
                'type' => 'select',
                'options' => ['normal' => '正式环境', 'sandbox' => '沙箱环境'],
                'required' => true,
                'default' => 'normal',
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
            'name' => '支付宝',
            'icon' => 'alipay',
        ];
    }

    public function getPayTypes(array $config): array
    {
        return [$config['pay_type'] ?? 'web'];
    }

    public function getSupportedCurrencies(): array
    {
        return ['CNY'];
    }
}
