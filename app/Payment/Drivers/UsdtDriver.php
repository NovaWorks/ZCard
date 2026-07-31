<?php

namespace App\Payment\Drivers;

use App\Models\Order;
use App\Payment\Contracts\PaymentDriver;
use App\Payment\PaymentResult;
use Illuminate\Http\Request;

class UsdtDriver implements PaymentDriver
{
    /**
     * 将法币金额按配置汇率换算成 USDT。
     */
    protected function toUsdt(float $amount, array $config): string
    {
        $rate = (float) ($config['rate'] ?? 1);

        return $rate > 0 ? bcdiv((string) $amount, (string) $rate, 6) : (string) $amount;
    }

    public function pay(Order $order, array $config): PaymentResult
    {
        $wallet = $config['wallet_address'] ?? '';
        // order->amount 是分,先转元再÷汇率
        $yuan = bcdiv((string) $order->amount, '100', 2);
        $usdt = $this->toUsdt((float) $yuan, $config);

        // 形如 tron:Txxx...?amount=1.234567，钱包 App 识别后可自动填金额。
        $content = 'tron:' . $wallet . '?amount=' . $usdt;

        return PaymentResult::qrcode($content);
    }

    public function verifyCallback(Request $request, array $config): ?array
    {
        $apiKey = $config['api_key'] ?? '';
        $provided = $request->header('X-API-Key') ?: $request->input('api_key');

        if (!hash_equals((string) $apiKey, (string) $provided)) {
            return null;
        }

        $data = $request->all();

        if (($data['status'] ?? '') !== 'paid' && ($data['trade_status'] ?? '') !== 'SUCCESS') {
            return null;
        }

        // 回调 amount 是 USDT 数值,需按汇率反算回分(与下单时 pay() 互逆)
        // 下单:fen→元(bcdiv/100)→USDT(÷rate);回调:USDT×rate×100=fen
        $usdtAmount = (float) ($data['amount'] ?? 0);
        $rate = (float) ($config['rate'] ?? 1);
        $fen = $rate > 0 ? (int) round($usdtAmount * $rate * 100) : (int) round($usdtAmount * 100);

        return [
            'channel_order_no' => $data['tx_id'] ?? ($data['channel_order_no'] ?? null),
            'out_trade_no' => $data['out_trade_no'] ?? null,
            'amount' => $fen, // 归一到分
            'raw' => $data,
        ];
    }

    public function getConfigFields(): array
    {
        return [
            'wallet_address' => [
                'label' => 'USDT (TRC20) 收款钱包地址',
                'type' => 'text',
                'required' => true,
            ],
            'api_key' => [
                'label' => '回调签名密钥(API Key)',
                'type' => 'text',
                'required' => true,
            ],
            'rate' => [
                'label' => '法币兑 USDT 汇率(1 USDT = ? 法币)',
                'type' => 'number',
                'required' => true,
                'default' => 1,
            ],
            'expire_minutes' => [
                'label' => '订单有效时长(分钟)',
                'type' => 'number',
                'required' => true,
                'default' => 30,
            ],
            'target_currency' => [
                'label' => '收款货币',
                'type' => 'text',
                'required' => false,
                'default' => 'USDT',
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
            'name' => 'USDT',
            'icon' => '₮',
        ];
    }

    public function getSupportedCurrencies(): array
    {
        return ['USDT'];
    }
}
