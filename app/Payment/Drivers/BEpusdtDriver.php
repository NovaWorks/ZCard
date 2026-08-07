<?php

namespace App\Payment\Drivers;

use App\Payment\Contracts\Payable;
use App\Payment\AbstractPaymentDriver;
use App\Payment\PaymentResult;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

/**
 * BEpusdt(USDT/USDC 等加密货币支付网关)驱动。
 *
 * 对接:https://github.com/v03413/BEpusdt
 * - 下单:POST {api_url}/api/v1/order/create-order (MD5 签名)
 * - 回调:BEpusdt 主动 POST notify_url,JSON 格式,MD5 验签
 * - 认证:无 pid,用商户 API Token 做签名(末尾直接追加 token)
 * - 法币:fiat 字段(CNY/USD/EUR/GBP/JPY),amount 为法币金额(元)
 * - 币种限定:currencies 字段(如 USDT,USDC),支持黑名单(-ETH)
 * - 回调 status:1=待支付 2=支付成功 3=超时;成功需返回 success
 *
 * 与 EpuSdt 的差异:端点/api 版本/认证方式(无 pid)/签名拼接(无 &)均不同,
 * 故单独实现本驱动。
 */
class BEpusdtDriver extends AbstractPaymentDriver
{
        /**
     * BEpusdt MD5 签名(与 EpuSdt 不同,本驱动末尾直接追加 token,中间无 &):
     * 1. 剔除 signature 字段及空值(null / "")
     * 2. 参数名按 ASCII 字典序排序
     * 3. 用 key=value 以 & 拼接
     * 4. 末尾直接追加 API Token(无分隔符)
     * 5. md5 取 32 位小写
     */
    protected function sign(array $params, string $apiToken): string
    {
        unset($params['signature']);
        $params = array_filter($params, fn ($v) => $v !== '' && $v !== null);
        ksort($params, SORT_STRING);

        $pairs = [];
        foreach ($params as $k => $v) {
            // 布尔值需小写字符串参与签名(reselect 字段文档要求)
            if (is_bool($v)) {
                $v = $v ? 'true' : 'false';
            }
            $pairs[] = $k.'='.$v;
        }

        return md5(implode('&', $pairs).$apiToken);
    }

    public function pay(Payable $order, array $config): PaymentResult
    {
        $apiUrl = rtrim($config['api_url'] ?? '', '/');
        $apiToken = $config['api_token'] ?? '';
        $fiat = strtolower($config['fiat'] ?? 'cny');
        // currencies 配置为数组(后台多选),下单时拼成 BEpusdt 要求的逗号分隔串
        // 如 ['USDT','USDC'] → 'USDT,USDC';留空则不传(不限币种,用户在收银台自选)
        $currencyList = $config['currencies'] ?? [];
        if (is_string($currencyList)) {
            $currencyList = array_filter(array_map('trim', explode(',', $currencyList)));
        }
        $currencies = implode(',', array_filter((array) $currencyList, fn ($v) => $v !== ''));
        $timeout = (int) ($config['timeout'] ?? 0);

        $notifyUrl = $this->namedUrl('payment.notify', ['channel' => 'bepusdt']);
        $redirectUrl = $this->namedUrl('payment.return', ['code' => 'bepusdt']).'?order_no='.$order->getPayableKey();

        $params = [
            'order_id' => $order->getPayableKey(),
            'amount' => (float) bcdiv((string) $order->getPayableAmount(), '100', 2), // 分→元(法币)
            'fiat' => strtoupper($fiat),
            'notify_url' => $notifyUrl,
            'redirect_url' => $redirectUrl,
            'name' => $order->getPayableKey(),
        ];
        // 可选参数(非空才传,空值会进入"地址独占模式")
        if ($currencies !== '') {
            $params['currencies'] = $currencies;
        }
        if ($timeout >= 180) {
            $params['timeout'] = $timeout;
        }

        $params['signature'] = $this->sign($params, $apiToken);

        $response = Http::timeout(15)->post($apiUrl.'/api/v1/order/create-order', $params);

        if (! $response->ok()) {
            throw new \RuntimeException('BEpusdt 下单失败: HTTP '.$response->status());
        }

        $data = $response->json();
        if (($data['status_code'] ?? 0) !== 200) {
            throw new \RuntimeException('BEpusdt 下单失败: '.($data['message'] ?? '未知错误'));
        }

        $paymentUrl = $data['data']['payment_url'] ?? null;
        if (! $paymentUrl) {
            throw new \RuntimeException('BEpusdt 未返回支付链接');
        }

        return PaymentResult::redirect($paymentUrl);
    }

    public function verifyCallback(Request $request, array $config): ?array
    {
        $apiToken = $config['api_token'] ?? '';
        $data = $request->all();

        // status:1=待支付 2=支付成功 3=超时;仅 2 视为成功
        if ((int) ($data['status'] ?? 0) !== 2) {
            return null;
        }

        // MD5 验签
        $expected = $this->sign($data, $apiToken);
        $provided = $data['signature'] ?? '';

        if (! hash_equals($expected, (string) $provided)) {
            return null;
        }

        // amount 是法币金额(元),转回分
        $amountYuan = (float) ($data['amount'] ?? 0);

        return [
            'channel_order_no' => $data['block_transaction_id'] ?? ($data['trade_id'] ?? null),
            'out_trade_no' => $data['order_id'] ?? null,
            'amount' => (int) round($amountYuan * 100), // 元→分
            'raw' => $data,
        ];
    }

    public function getConfigFields(): array
    {
        return [
            'api_url' => [
                'label' => 'BEpusdt 服务地址',
                'type' => 'text',
                'required' => true,
                'placeholder' => '如 https://pay.example.com',
            ],
            'api_token' => [
                'label' => '商户 API Token',
                'type' => 'text',
                'required' => true,
                'help' => '在 BEpusdt 后台获取的商户 Token,用于接口签名。',
            ],
            'fiat' => [
                'label' => '法币币种',
                'type' => 'select',
                'options' => [
                    'CNY' => '人民币 (CNY)',
                    'USD' => '美元 (USD)',
                    'EUR' => '欧元 (EUR)',
                    'GBP' => '英镑 (GBP)',
                    'JPY' => '日元 (JPY)',
                ],
                'required' => true,
                'default' => 'CNY',
            ],
            'currencies' => [
                'label' => '支持的加密货币(多选)',
                'type' => 'multiselect',
                'options' => [
                    'USDT' => 'USDT (泰达币)',
                    'USDC' => 'USDC (美元币)',
                    'TRX' => 'TRX (波场)',
                    'ETH' => 'ETH (以太坊)',
                    'BNB' => 'BNB (币安币)',
                    'TON' => 'TON (Toncoin)',
                    'GRAM' => 'GRAM (Ton 原生)',
                ],
                'required' => false,
                'default' => ['USDT'],
                'help' => '勾选允许用户支付的加密货币(可多选)。用户在 BEpusdt 收银台自选具体网络(如 TRC20/ERC20)。留空则不限制。具体可用币种以 BEpusdt 服务端配置为准。',
            ],
            'timeout' => [
                'label' => '订单超时(秒)',
                'type' => 'number',
                'required' => false,
                'default' => 600,
                'help' => '订单有效时长,最低 180 秒,默认 600 秒。',
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
            'name' => 'BEpusdt',
            'icon' => '🪙',
        ];
    }

    public function getPayTypes(array $config): array
    {
        return ['usdt'];
    }

    public function getSupportedCurrencies(): array
    {
        return ['CNY', 'USD', 'EUR', 'GBP', 'JPY'];
    }
}
