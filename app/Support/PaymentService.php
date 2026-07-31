<?php

namespace App\Support;

use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentChannel;
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

    public function createPayment(Order $order, int $channelId): array
    {
        $channel = PaymentChannel::findOrFail($channelId);
        if (! $channel->enabled) {
            throw new \RuntimeException(__('messages.payment.channel_disabled'));
        }

        $driver = $this->resolveDriver($channel);
        $config = $channel->config ?? [];
        // 通道目标货币 + 汇率(spec §5.3):config 未配则用驱动首个支持货币 + 汇率 1
        $supported = $driver->getSupportedCurrencies();
        $targetCur = strtoupper($config['target_currency'] ?? ($supported[0] ?? 'CNY'));
        $rate = (float) ($config['exchange_rate'] ?? 1);
        if ($rate <= 0) {
            $rate = 1.0;
        }
        // 基础货币分 → 目标货币分:分→元 ×rate → 元 → 分(四舍五入)
        $baseYuan = bcdiv((string) $order->amount, '100', 8);
        $targetYuan = bcmul($baseYuan, (string) $rate, 8);
        $targetMin = (int) $this->bcRound(bcmul($targetYuan, '100', 8));

        $result = $driver->pay($order, $config);

        // 驱动若回报了实际发送金额则用之,否则用通道换算值
        $chargedCur = $result->currencySent ?? $targetCur;
        $chargedAmt = $result->amountSent ?? $targetMin;

        Payment::create([
            'order_id' => $order->id,
            'channel' => $channel->code,
            'amount' => $order->amount,
            'status' => 'pending',
            'charged_currency' => $chargedCur,
            'charged_amount' => $chargedAmt,
            'channel_exchange_rate' => $rate,
        ]);

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

        // 驱动统一返回 out_trade_no(=商户订单号)
        $orderNo = $data['out_trade_no'] ?? $data['order_no'] ?? null;
        $order = $orderNo ? Order::where('order_no', $orderNo)->first() : null;
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

    private function resolveDriver(PaymentChannel $channel): PaymentDriver
    {
        $driverClass = $channel->driver;
        if (! class_exists($driverClass)) {
            throw new \RuntimeException("支付 Driver 不存在: {$driverClass}");
        }
        return new $driverClass();
    }

    /** bcmath 四舍五入到整数(half away from zero) */
    private function bcRound(string $value): string
    {
        if (str_starts_with($value, '-')) {
            return bcsub($value, '0.5', 0);
        }
        return bcadd($value, '0.5', 0);
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
