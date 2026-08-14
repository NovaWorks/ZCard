<?php

namespace App\Payment\Drivers;

use App\Payment\AbstractPaymentDriver;
use App\Payment\Contracts\Payable;
use App\Payment\FiatAmount;
use App\Payment\PaymentResult;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

/**
 * EpuSdt(USDT 加密货币支付网关)驱动。
 *
 * 参考:https://github.com/GMWalletApp/epusdt
 * - 下单:POST {api_url}/payments/gmpay/v1/order/create-transaction (HMAC-SHA256 签名)
 * - 回调:epusdt 主动 POST notify_url,JSON 格式,HMAC-SHA256 验签
 * - 金额:法币(元),epusdt 内部转换为 USDT
 */
class EpuSdtDriver extends AbstractPaymentDriver
{
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
            $pairs[] = $k.'='.$v;
        }

        return hash_hmac('sha256', implode('&', $pairs), $secretKey);
    }

    public function pay(Payable $order, array $config): PaymentResult
    {
        $apiUrl = rtrim($config['api_url'] ?? '', '/');
        $pid = $config['pid'] ?? '';
        $secretKey = $config['secret_key'] ?? '';
        $currency = strtoupper($config['currency'] ?? ($config['target_currency'] ?? 'CNY'));
        $amount = FiatAmount::convertFromBase(
            $order->getPayableAmount(),
            $config['exchange_rate'] ?? '1',
            $currency
        );
        $token = $config['token'] ?? 'USDT';
        $network = $config['network'] ?? 'TRC20';

        $notifyUrl = $this->namedUrl('payment.notify', ['channel' => 'epusdt']);
        $redirectUrl = $this->namedUrl('payment.return', ['code' => 'epusdt']).'?order_no='.$order->getPayableKey();

        $params = [
            'pid' => (string) $pid,
            'order_id' => $order->getPayableKey(),
            'currency' => strtolower($currency),
            'amount' => FiatAmount::formatMinor($amount, $currency),
            'notify_url' => $notifyUrl,
            'redirect_url' => $redirectUrl,
            'name' => $order->getPayableKey(),
            // 区块链网络 + 支付代币(epusdt 新版支持,旧版忽略这两个字段不影响)
            'network' => $network,
            'token' => $token,
        ];

        $params['signature'] = $this->sign($params, $secretKey);

        $response = Http::timeout(15)->post($apiUrl.'/payments/gmpay/v1/order/create-transaction', $params);

        if (! $response->ok()) {
            throw new \RuntimeException('EpuSdt 下单失败: HTTP '.$response->status());
        }

        $data = $response->json();
        if (($data['status_code'] ?? 0) !== 200) {
            throw new \RuntimeException('EpuSdt 下单失败: '.($data['message'] ?? '未知错误'));
        }

        $paymentUrl = $data['data']['payment_url'] ?? null;
        if (! $paymentUrl) {
            throw new \RuntimeException('EpuSdt 未返回支付链接');
        }

        return PaymentResult::redirect($paymentUrl, $currency, $amount);
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

        $currency = strtoupper($config['currency'] ?? ($config['target_currency'] ?? 'CNY'));
        if (isset($data['currency']) && strtoupper((string) $data['currency']) !== $currency) {
            return null;
        }

        return [
            'channel_order_no' => $data['trade_id'] ?? null,
            'out_trade_no' => $data['order_id'] ?? null,
            'amount' => FiatAmount::fromMajor($data['amount'] ?? null, $currency),
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
            'network' => [
                'label' => '区块链网络',
                'type' => 'select',
                'options' => [
                    'TRC20' => 'TRC20 (波场 / Tron)',
                    'ERC20' => 'ERC20 (以太坊 / Ethereum)',
                    'BEP20' => 'BEP20 (币安链 / BSC)',
                ],
                'required' => true,
                'default' => 'TRC20',
                'help' => '选择收款 USDT 所在的链。TRC20 手续费最低,推荐。需 epusdt 服务端已启用对应链。',
            ],
            'token' => [
                'label' => '支付代币',
                'type' => 'text',
                'required' => true,
                'default' => 'USDT',
                'help' => '收款代币符号,通常为 USDT(可改为 USDC/USDP 等稳定币,需服务端支持)。',
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
            'name' => 'EpuSdt(USDT)',
            'icon' => 'usdt',
        ];
    }

    public function getPayTypes(array $config): array
    {
        return ['usdt'];
    }

    public function getSupportedCurrencies(): array
    {
        return ['CNY', 'USD'];
    }
}
