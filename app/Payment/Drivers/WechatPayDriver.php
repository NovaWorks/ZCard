<?php

namespace App\Payment\Drivers;

use App\Payment\Contracts\Payable;
use App\Payment\Contracts\PaymentDriver;
use App\Payment\PaymentResult;
use Illuminate\Http\Request;
use Yansongda\Pay\Pay;

class WechatPayDriver implements PaymentDriver
{
    /**
     * 构建传给 yansongda/laravel-pay v3 的配置数组。
     */
    protected function buildConfig(array $config): array
    {
        $mode = ($config['mode'] ?? 'normal') === 'sandbox'
            ? Pay::MODE_SANDBOX
            : Pay::MODE_NORMAL;

        $wechat = [
            'mch_id' => $config['mch_id'] ?? '',
            'mch_secret_key' => $config['mch_secret_key'] ?? '',
            'mch_secret_cert' => $config['mch_secret_cert'] ?? '',
            'mch_public_cert_path' => $config['mch_public_cert_path'] ?? '',
            'app_id' => $config['app_id'] ?? '',
            'mode' => $mode,
        ];

        // 回调验签需要「平台证书序列号 → 证书路径」映射;未配置时 SDK 会尝试
        // 通过 API 自动下载平台证书(需要 apiv3 密钥与商户证书)。
        if (! empty($config['wechat_platform_serial']) && ! empty($config['mch_public_cert_path'])) {
            $wechat['wechat_public_cert_path'] = [
                (string) $config['wechat_platform_serial'] => (string) $config['mch_public_cert_path'],
            ];
        }

        return [
            'wechat' => [
                'default' => $wechat,
            ],
        ];
    }

    public function pay(Payable $order, array $config): PaymentResult
    {
        // _force:yansongda/pay 的 Artful 容器由 Laravel PayServiceProvider 在启动时
        // 以空配置初始化,此后 Pay::config() 会静默失效;必须强制重建容器,
        // 否则通道配置(商户号/密钥)永远不会传给 SDK。
        Pay::config($this->buildConfig($config) + ['_force' => true]);

        $result = Pay::wechat()->scan([
            'out_trade_no' => $order->getPayableKey(),
            'description' => $order->getPayableKey(),
            'amount' => [
                'total' => (int) $order->getPayableAmount(), // 微信 V3 用分,order->amount 已是分
                'currency' => 'CNY',
            ],
        ]);

        // native（扫码）支付返回 code_url，供前端生成二维码。
        $codeUrl = $result['code_url'] ?? ($result->code_url ?? null);

        return PaymentResult::qrcode((string) $codeUrl);
    }

    public function verifyCallback(Request $request, array $config): ?array
    {
        // _force:同上,确保通道配置真正注入 SDK(否则验签/解密用的是空配置)。
        Pay::config($this->buildConfig($config) + ['_force' => true]);
        try {
            // 安全关键:必须携带【原始 body + 全部请求头】给 SDK 验签
            // (Wechatpay-Signature / -Timestamp / -Nonce / -Serial)。
            // 直接传参数数组会丢掉 headers → 验签恒失败(支付永不确认)。
            $result = Pay::wechat()->callback([
                'body' => $request->getContent(),
                'headers' => $request->headers->all(),
            ]);
        } catch (\Throwable $e) {
            return null;
        }

        $data = method_exists($result, 'all') ? $result->all() : (array) $result;

        // V3 通知结构:外层 {event_type, resource:{...}};SDK 解密后把明文放回
        // resource.ciphertext(保留 algorithm/nonce/associated_data 等元数据)。
        $resource = is_array($data['resource'] ?? null) ? $data['resource'] : $data;
        if (is_array($resource) && is_array($resource['ciphertext'] ?? null)) {
            $resource = $resource['ciphertext'];
        }

        $tradeState = $data['event_type'] ?? ($resource['trade_state'] ?? null);
        $success = in_array($tradeState, ['TRANSACTION.SUCCESS', 'SUCCESS'], true);

        if (! $success) {
            return null;
        }

        $amount = $resource['amount']['total'] ?? ($resource['amount']['payer_total'] ?? null); // 微信已是分

        return [
            'channel_order_no' => $resource['transaction_id'] ?? null,
            'out_trade_no' => $resource['out_trade_no'] ?? null,
            'amount' => $amount !== null ? (int) $amount : null,
            'raw' => $data,
        ];
    }

    public function getConfigFields(): array
    {
        return [
            'mch_id' => [
                'label' => '商户号',
                'type' => 'text',
                'required' => true,
            ],
            'app_id' => [
                'label' => '应用 AppID',
                'type' => 'text',
                'required' => true,
            ],
            'mch_secret_key' => [
                'label' => '商户 API v3 密钥',
                'type' => 'text',
                'required' => true,
            ],
            'mch_secret_cert' => [
                'label' => '商户 API 私钥(apiclient_key.pem)',
                'type' => 'textarea',
                'required' => true,
            ],
            'mch_public_cert_path' => [
                'label' => '商户证书路径(apiclient_cert.pem)',
                'type' => 'text',
                'required' => true,
            ],
            'wechat_platform_serial' => [
                'label' => '微信支付平台证书序列号(可选)',
                'type' => 'text',
                'required' => false,
                'help' => '用于回调验签的平台证书序列号(与 apiclient_cert.pem 配套)。留空时由 SDK 自动下载平台证书。',
            ],
            'mode' => [
                'label' => '运行模式',
                'type' => 'select',
                'options' => ['normal' => '正式环境', 'sandbox' => '沙箱/模拟环境'],
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
            'name' => '微信支付',
            'icon' => 'wechat',
        ];
    }

    public function getPayTypes(array $config): array
    {
        return ['wechat'];
    }

    public function getSupportedCurrencies(): array
    {
        return ['CNY'];
    }
}
