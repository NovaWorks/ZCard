<?php

namespace App\Payment\Drivers;

use App\Models\Order;
use App\Payment\Contracts\PaymentDriver;
use App\Payment\PaymentResult;
use Illuminate\Http\Request;
use Yansongda\Pay\Pay;

class AlipayDriver implements PaymentDriver
{
    /**
     * 构建传给 yansongda/laravel-pay v3 的配置数组。
     */
    protected function buildConfig(array $config): array
    {
        $mode = ($config['mode'] ?? 'normal') === 'sandbox'
            ? \Yansongda\Pay\Pay::MODE_SANDBOX
            : \Yansongda\Pay\Pay::MODE_NORMAL;

        return [
            'alipay' => [
                'default' => [
                    'app_id' => $config['app_id'] ?? '',
                    'app_secret_cert' => $config['private_key'] ?? '',
                    'app_public_cert' => $config['public_key'] ?? '',
                    'alipay_public_cert' => $config['public_key'] ?? '',
                    'mode' => $mode,
                ],
            ],
        ];
    }

    public function pay(Order $order, array $config): PaymentResult
    {
        Pay::setAlipayConfig($this->buildConfig($config));

        $result = Pay::alipay()->web([
            'out_trade_no' => $order->order_no,
            'total_amount' => (string) $order->amount,
            'subject' => $order->order_no,
        ]);

        // yansongda alipay web 返回的是一段可自动提交的 HTML 表单。
        $body = method_exists($result, 'getBody') ? (string) $result->getBody() : (string) $result;

        return PaymentResult::form($body);
    }

    public function verifyCallback(Request $request, array $config): ?array
    {
        Pay::setAlipayConfig($this->buildConfig($config));

        try {
            $result = Pay::alipay()->callback($request->all());
        } catch (\Throwable $e) {
            return null;
        }

        $data = method_exists($result, 'all') ? $result->all() : (array) $result;

        $tradeStatus = $data['trade_status'] ?? null;
        if (!in_array($tradeStatus, ['TRADE_SUCCESS', 'TRADE_FINISHED'], true)) {
            return null;
        }

        return [
            'channel_order_no' => $data['trade_no'] ?? null,
            'out_trade_no' => $data['out_trade_no'] ?? null,
            'amount' => $data['total_amount'] ?? null,
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
            'public_key' => [
                'label' => '支付宝公钥',
                'type' => 'textarea',
                'required' => true,
            ],
            'private_key' => [
                'label' => '应用私钥',
                'type' => 'textarea',
                'required' => true,
            ],
            'mode' => [
                'label' => '运行模式',
                'type' => 'select',
                'options' => ['normal' => '正式环境', 'sandbox' => '沙箱环境'],
                'required' => true,
                'default' => 'normal',
            ],
        ];
    }

    public function getInfo(): array
    {
        return [
            'name' => '支付宝',
            'icon' => '💰',
        ];
    }
}
