<?php

namespace App\Support;

use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentChannel;
use App\Models\Recharge;
use App\Payment\Contracts\Payable;
use App\Payment\Contracts\PaymentDriver;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class PaymentService
{
    public function getEnabledChannels(): Collection
    {
        return PaymentChannel::where('enabled', true)->orderBy('sort')->get();
    }

    public function getAllChannels(): Collection
    {
        return PaymentChannel::orderBy('sort')->get();
    }

    /**
     * 向网关发起收款。
     *
     * 同时服务于发卡订单(Order)与充值单(Recharge):两者均实现 Payable,
     * 驱动只读取单号与金额,与本服务无关业务差异。
     */
    public function createPayment(Payable $payable, int $channelId): array
    {
        $channel = PaymentChannel::findOrFail($channelId);
        if (! $channel->enabled) {
            throw new \RuntimeException(__('messages.payment.channel_disabled'));
        }

        $driver = $this->resolveDriver($channel);
        $config = $channel->config ?? [];
        // 通道目标货币 + 汇率(spec §5.3):作为审计元数据记录。
        // 注意:各驱动 pay() 实际以「基础货币」金额向网关发起收款(未做通道换算),
        // 因此 charged_amount = payable.amount(基础货币分),与驱动 verifyCallback 回报的
        // 基础货币分口径一致,保证回调金额校验正确。target_currency/exchange_rate 仅记录
        // 该通道声明的目标货币与汇率,供对账/审计使用。
        $supported = $driver->getSupportedCurrencies();
        $targetCur = strtoupper($config['target_currency'] ?? ($supported[0] ?? 'CNY'));
        $rate = (float) ($config['exchange_rate'] ?? 1);
        if ($rate <= 0) {
            $rate = 1.0;
        }

        $result = $driver->pay($payable, $config);

        // 写支付流水:按 Payable 类型填 order_id 或 recharge_id,另一个留空。
        $payload = [
            'channel' => $channel->code,
            'amount' => $payable->getPayableAmount(),
            'status' => 'pending',
            'charged_currency' => $result->currencySent ?? $targetCur,
            'charged_amount' => $result->amountSent ?? (int) $payable->getPayableAmount(),
            'channel_exchange_rate' => $rate,
        ];
        if ($payable instanceof Recharge) {
            $payload['recharge_id'] = $payable->id;
        } else {
            $payload['order_id'] = $payable->id;
        }
        Payment::create($payload);

        return $result->toArray();
    }

    public function handleCallback(string $channelCode, Request $request): string
    {
        $channel = PaymentChannel::where('code', $channelCode)->first();
        if (! $channel) {
            return 'fail: channel not found';
        }

        // 安全兜底:凭据未配置则拒绝(防空 key 伪造签名,参考 acg-faka payCredentialConfigured)
        $config = $channel->config ?? [];
        if (! $this->credentialsConfigured($config)) {
            return 'fail: credentials not configured';
        }

        $driver = $this->resolveDriver($channel);
        $data = $driver->verifyCallback($request, $config);

        if (! $data) {
            return 'fail: verify failed';
        }

        // 驱动统一返回 out_trade_no(=商户业务单号),按前缀分流到订单或充值单
        $bizNo = $data['out_trade_no'] ?? $data['order_no'] ?? null;
        if (! $bizNo) {
            return 'fail: biz_no missing';
        }

        if (str_starts_with($bizNo, 'RCH')) {
            return $this->handleRechargeCallback($bizNo, $channelCode, $data, $request);
        }

        return $this->handleOrderCallback($bizNo, $channelCode, $data, $request);
    }

    /**
     * 发卡订单回调:验金额 → 标记支付成功 → 触发发卡(OrderPaid 事件)。
     */
    private function handleOrderCallback(string $orderNo, string $channelCode, array $data, Request $request): string
    {
        $order = Order::where('order_no', $orderNo)->first();
        if (! $order) {
            return 'fail: order not found';
        }

        // 幂等:订单已支付则直接返回 success(第三方停止重试)
        if ($order->status === 'paid') {
            return 'success';
        }

        // 金额校验:驱动回调 amount 是目标货币最小单位,对比该订单最近一笔 payment 的 charged_amount
        $payment = Payment::where('order_id', $order->id)->orderByDesc('id')->first();
        $expectFen = $payment ? (int) $payment->charged_amount : (int) $order->amount;
        $actualFen = (int) ($data['amount'] ?? -1);
        if ($actualFen !== $expectFen) {
            return 'fail: amount mismatch';
        }

        Payment::where('order_id', $order->id)->where('channel', $channelCode)
            ->update(['status' => 'success', 'paid_at' => now(), 'raw' => $request->all()]);

        // markPaid 内部有 lockForUpdate + 状态检查,并发时第二个会抛异常
        // 捕获后视为幂等(已被其他并发请求处理),返回 success 让第三方停止重试
        try {
            app(OrderService::class)->markPaid($order->order_no);
        } catch (\RuntimeException $e) {
            if ($order->fresh()->status === 'paid') {
                return 'success';
            }
            throw $e;
        }

        return 'success';
    }

    /**
     * 充值单回调:验金额 → 标记支付成功 → 调 BillService::record 入账余额。
     * 入账幂等:以充值单 status === 'paid' 为准,已入账则直接返回 success。
     */
    private function handleRechargeCallback(string $rechargeNo, string $channelCode, array $data, Request $request): string
    {
        $recharge = Recharge::where('recharge_no', $rechargeNo)->first();
        if (! $recharge) {
            return 'fail: recharge not found';
        }

        // 幂等:已支付(已入账)直接返回 success
        if ($recharge->status === Recharge::STATUS_PAID) {
            return 'success';
        }

        // 金额校验
        $payment = Payment::where('recharge_id', $recharge->id)->orderByDesc('id')->first();
        $expectFen = $payment ? (int) $payment->charged_amount : (int) $recharge->amount;
        $actualFen = (int) ($data['amount'] ?? -1);
        if ($actualFen !== $expectFen) {
            return 'fail: amount mismatch';
        }

        // 标记支付流水 + 充值单为已支付,再入账(BillService 内部有行锁 + 幂等保护)
        $rechargeLock = \Illuminate\Support\Facades\DB::transaction(function () use ($recharge, $channelCode, $request) {
            $locked = Recharge::where('id', $recharge->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== Recharge::STATUS_PENDING) {
                return $locked; // 已被并发处理
            }
            $locked->update(['status' => Recharge::STATUS_PAID, 'paid_at' => now()]);
            Payment::where('recharge_id', $locked->id)->where('channel', $channelCode)
                ->update(['status' => 'success', 'paid_at' => now(), 'raw' => $request->all()]);
            return $locked;
        });

        if ($rechargeLock->status === Recharge::STATUS_PAID) {
            try {
                BillService::record(
                    $recharge->user_id,
                    (int) $recharge->amount,
                    \App\Models\Bill::TYPE_INCOME,
                    __('messages.recharge.credit', ['no' => $recharge->recharge_no]),
                );
            } catch (\Throwable $e) {
                // 余额入账异常:回滚充值单状态,让第三方重试回调
                \Illuminate\Support\Facades\Log::error('充值入账失败: ' . $rechargeNo . ' ' . $e->getMessage());
                Recharge::where('id', $recharge->id)->where('status', Recharge::STATUS_PAID)
                    ->update(['status' => Recharge::STATUS_PENDING, 'paid_at' => null]);
                throw $e;
            }
        }

        return 'success';
    }

    private function resolveDriver(PaymentChannel $channel): PaymentDriver
    {
        $driverClass = $channel->driver;
        if (! class_exists($driverClass)) {
            throw new \RuntimeException("支付 Driver 不存在: {$driverClass}");
        }
        return new $driverClass();
    }


    /**
     * 检查凭据是否已配置(至少有一个敏感字段非空)。
     * 参考自 acg-faka payCredentialConfigured,防止空 key 伪造签名。
     */
    private function credentialsConfigured(array $config): bool
    {
        $sensitiveKeys = ['key', 'secret', 'secret_key', 'private_key', 'public_key',
            'app_secret', 'api_key', 'client_secret', 'webhook_secret', 'mch_secret_key'];
        foreach ($sensitiveKeys as $k) {
            if (! empty($config[$k])) {
                return true;
            }
        }
        return false;
    }

    public function saveChannelConfig(int $channelId, array $config): void
    {
        PaymentChannel::where('id', $channelId)->update(['config' => $config]);
    }

    public function toggleChannel(int $channelId, bool $enabled): void
    {
        PaymentChannel::where('id', $channelId)->update(['enabled' => $enabled]);
    }
}
