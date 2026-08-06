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

        return [
            'wechat' => [
                'default' => [
                    'mch_id' => $config['mch_id'] ?? '',
                    'mch_secret_key' => $config['mch_secret_key'] ?? '',
                    'mch_secret_cert' => $config['mch_secret_cert'] ?? '',
                    'mch_public_cert_path' => $config['mch_public_cert_path'] ?? '',
                    'app_id' => $config['app_id'] ?? '',
                    'mode' => $mode,
                ],
            ],
        ];
    }

    public function pay(Payable $order, array $config): PaymentResult
    {
        Pay::config($this->buildConfig($config));

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
        Pay::config($this->buildConfig($config));

        try {
            $result = Pay::wechat()->callback($request->all());
        } catch (\Throwable $e) {
            return null;
        }

        $data = method_exists($result, 'all') ? $result->all() : (array) $result;

        $tradeState = $data['trade_state'] ?? ($data['event_type'] ?? null);
        $success = in_array($tradeState, ['SUCCESS', 'TRANSACTION.SUCCESS'], true);

        if (! $success) {
            return null;
        }

        $amount = $data['amount']['total'] ?? ($data['amount']['payer_total'] ?? null); // 微信已是分

        return [
            'channel_order_no' => $data['transaction_id'] ?? null,
            'out_trade_no' => $data['out_trade_no'] ?? null,
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
            'icon' => '💚',
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
