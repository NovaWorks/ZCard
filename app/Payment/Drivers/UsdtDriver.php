<?php

namespace App\Payment\Drivers;

use App\Payment\Contracts\Payable;
use App\Payment\Contracts\PaymentDriver;
use App\Payment\PaymentResult;
use Illuminate\Http\Request;

/**
 * USDT 链上转账支付驱动(无第三方网关,直接展示收款地址二维码)。
 *
 * 支持多链选择(TRC20/ERC20/BSC 等),根据所选链生成对应的钱包 URI,
 * 钱包 App 识别后可自动填入收款地址与金额。
 */
class UsdtDriver implements PaymentDriver
{
    /**
     * 支持的区块链网络:code → [协议 scheme, 显示名]。
     * scheme 用于生成钱包 URI(钱包 App 靠它识别网络)。
     */
    public const CHAINS = [
        'trx' => ['tron',      'TRC20 (波场 / Tron)'],
        'eth' => ['ethereum',  'ERC20 (以太坊 / Ethereum)'],
        'bsc' => ['ethereum',  'BEP20 (币安链 / BSC)'],
        'poly' => ['ethereum',  'Polygon (Matic)'],
        'arb' => ['ethereum',  'Arbitrum One'],
        'op' => ['ethereum',  'Optimism'],
        'tron' => ['tron',      'TRC20 (波场,旧名)'],
    ];

    /** 取链的钱包 URI scheme(默认 tron) */
    protected function chainScheme(array $config): string
    {
        $chain = strtolower((string) ($config['chain'] ?? 'trx'));

        return self::CHAINS[$chain][0] ?? 'tron';
    }

    /**
     * 将法币金额按配置汇率换算成 USDT。
     */
    protected function toUsdt(float $amount, array $config): string
    {
        $rate = (float) ($config['rate'] ?? 1);

        return $rate > 0 ? bcdiv((string) $amount, (string) $rate, 6) : (string) $amount;
    }

    public function pay(Payable $order, array $config): PaymentResult
    {
        $wallet = $config['wallet_address'] ?? '';
        $scheme = $this->chainScheme($config);
        // order->amount 是分,先转元再÷汇率
        $yuan = bcdiv((string) $order->getPayableAmount(), '100', 2);
        $usdt = $this->toUsdt((float) $yuan, $config);

        // 钱包 URI:trc20→tron:Txxx...?amount=1.234567;erc20→ethereum:0x...?value=...
        // 各钱包 App 据此识别网络并自动填入地址与金额。
        $content = $scheme.':'.$wallet.'?amount='.$usdt;

        return PaymentResult::qrcode($content);
    }

    public function verifyCallback(Request $request, array $config): ?array
    {
        // 安全(M-14):配置了 secret_key 时回调必须携带 HMAC-SHA256 签名(字段排序拼接);
        // 未配置时回退到旧版静态 API Key 比较(兼容存量链上监听器)。
        $secret = (string) ($config['secret_key'] ?? '');
        if ($secret !== '') {
            $data = $request->all();
            $provided = strtolower(trim((string) ($request->header('X-Signature') ?: ($data['signature'] ?? ''))));
            unset($data['signature'], $data['sign']);
            ksort($data);
            $parts = [];
            foreach ($data as $k => $v) {
                if ($v === '' || $v === null || is_array($v)) {
                    continue;
                }
                $parts[] = $k.'='.$v;
            }
            $canonical = implode('&', $parts);
            if ($provided === '' || ! hash_equals(hash_hmac('sha256', $canonical, $secret), $provided)) {
                return null;
            }
        } else {
            $apiKey = $config['api_key'] ?? '';
            $provided = $request->header('X-API-Key') ?: $request->input('api_key');

            if (! hash_equals((string) $apiKey, (string) $provided)) {
                return null;
            }
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
        // 链类型选项(从 CHAINS 常量生成 code => 显示名,后端会归一化成 [{value,label}])
        $chainOptions = [];
        foreach (self::CHAINS as $code => $meta) {
            [$scheme, $label] = $meta;
            if (! isset($chainOptions[$code])) {
                $chainOptions[$code] = $label;
            }
        }

        return [
            'chain' => [
                'label' => '收款链/网络',
                'type' => 'select',
                'options' => $chainOptions,
                'required' => true,
                'default' => 'trx',
                'help' => '选择 USDT 所在的区块链网络。TRC20 手续费最低、到账最快,推荐使用。',
            ],
            'wallet_address' => [
                'label' => 'USDT 收款钱包地址',
                'type' => 'text',
                'required' => true,
                'help' => '上面所选链网络的 USDT 收款地址(务必与所选链匹配,否则转账将丢失)。',
            ],
            'api_key' => [
                'label' => '回调签名密钥(API Key)',
                'type' => 'text',
                'required' => true,
            ],
            'secret_key' => [
                'label' => '回调 HMAC 密钥(可选,推荐)',
                'type' => 'text',
                'required' => false,
                'help' => '配置后回调必须携带 X-Signature(HMAC-SHA256,按字段名排序拼接后以本密钥签名),比静态 API Key 更安全;留空则回退 API Key 校验(兼容旧监听器)',
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
            'icon' => 'usdt',
        ];
    }

    public function getPayTypes(array $config): array
    {
        return ['usdt'];
    }

    public function getSupportedCurrencies(): array
    {
        return ['USDT'];
    }
}
