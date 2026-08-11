<?php

namespace App\Support;

use App\Models\Bill;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentChannel;
use App\Models\Recharge;
use App\Models\SupplierAccount;
use App\Models\SupplierLedgerEntry;
use App\Payment\Contracts\Payable;
use App\Payment\Contracts\PaymentDriver;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

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
     * 按通道配置计算手续费。
     *
     * 返回 [手续费(分), 应付金额(分)]:
     * - fee <= 0 → 无手续费,[0, 原金额]
     * - fee_type = fixed → 手续费 = fee × 100(分);percent → 手续费 = 原金额 × fee ÷ 100(fee=5 表示 5%)
     * - fee_bearer = customer → 应付金额 = 原金额 + 手续费(加到用户付款额)
     * - fee_bearer = merchant → 应付金额不变(手续费从商户实收扣,仅记录)
     *
     * @return array{0: int, 1: int} [feeFen, payAmountFen]
     */
    public function calcFee(PaymentChannel $channel, int $amountFen): array
    {
        $fee = (float) ($channel->fee ?? 0);
        if ($fee <= 0) {
            return [0, $amountFen];
        }

        $feeFen = $channel->fee_type === 'fixed'
            ? (int) round($fee * 100) // 固定金额:元 → 分
            : (int) round($amountFen * $fee / 100); // 百分比:fee=5 表示 5%(与后台 UI 提示一致)

        $payAmount = ($channel->fee_bearer ?? 'merchant') === 'customer'
            ? $amountFen + $feeFen
            : $amountFen;

        return [$feeFen, $payAmount];
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

        // 通道手续费:按配置计算。商户承担 → 应付金额不变,仅记录 fee;
        // 客户承担 → 应付金额 = 原金额 + 手续费(驱动以含手续费金额发起收款)。
        $original = $payable;
        [$feeFen, $payAmountFen] = $this->calcFee($channel, (int) $payable->getPayableAmount());
        if ($payAmountFen !== (int) $payable->getPayableAmount()) {
            $payable = new class($payable, $payAmountFen) implements Payable
            {
                public function __construct(private Payable $inner, private int $amount) {}

                public function getPayableKey(): string
                {
                    return $this->inner->getPayableKey();
                }

                public function getPayableAmount(): int
                {
                    return $this->amount;
                }

                public function getPayableType(): string
                {
                    return $this->inner->getPayableType();
                }
            };
        }

        $result = $driver->pay($payable, $config);

        // 写支付流水:按 Payable 类型填 order_id 或 recharge_id,另一个留空。
        $payload = [
            'channel' => $channel->code,
            'amount' => (int) $payable->getPayableAmount(),
            'status' => 'pending',
            'fee' => $feeFen,
            'charged_currency' => $result->currencySent ?? $targetCur,
            'charged_amount' => $result->amountSent ?? (int) $payable->getPayableAmount(),
            'channel_exchange_rate' => $rate,
        ];
        if ($original instanceof Recharge) {
            $payload['recharge_id'] = $original->id;
        } elseif ($original instanceof Order) {
            $payload['order_id'] = $original->id;
        } else {
            throw new \RuntimeException('不支持的 Payable 类型: '.get_class($original));
        }
        Payment::create($payload);

        return $result->toArray();
    }

    /**
     * 购物车聚合支付:多个订单一次付款。
     *
     * 用主订单(第一个)的订单号作为网关单号发起收款,金额为各订单之和;
     * 支付流水通过 order_id=主订单 + order_ids=全部订单 关联,
     * 回调时按主订单找到流水,再对 order_ids 内所有订单统一 markPaid。
     *
     * @return array 驱动 PaymentResult 的 toArray()
     */
    public function createBatchPayment(array $orderIds, int $channelId): array
    {
        $channel = PaymentChannel::findOrFail($channelId);
        if (! $channel->enabled) {
            throw new \RuntimeException(__('messages.payment.channel_disabled'));
        }

        $ids = array_values(array_unique(array_map('intval', $orderIds)));
        if (empty($ids)) {
            throw new \RuntimeException(__('messages.payment.order_ids_required'));
        }

        $orders = Order::whereIn('id', $ids)->get();
        if ($orders->count() !== count($ids)) {
            throw new \RuntimeException(__('messages.payment.order_not_found'));
        }
        if ($orders->contains(fn ($o) => $o->status !== 'pending')) {
            throw new \RuntimeException(__('messages.payment.order_not_pending'));
        }

        $total = (int) $orders->sum('amount');
        /** @var Order $main 主订单:网关单号 + 支付流水主关联 */
        $main = $orders->first();

        // 聚合手续费:基于订单总额计算(与 createPayment 同逻辑)
        [$feeFen, $payTotal] = $this->calcFee($channel, $total);

        // 聚合 Payable:单号取主订单,金额取总和(含客户承担手续费),驱动只关心这两项
        $payable = new class($main, $payTotal) implements Payable
        {
            public function __construct(private Order $main, private int $total) {}

            public function getPayableKey(): string
            {
                return $this->main->order_no;
            }

            public function getPayableAmount(): int
            {
                return $this->total;
            }

            public function getPayableType(): string
            {
                return 'order';
            }
        };

        $driver = $this->resolveDriver($channel);
        $config = $channel->config ?? [];
        $supported = $driver->getSupportedCurrencies();
        $targetCur = strtoupper($config['target_currency'] ?? ($supported[0] ?? 'CNY'));
        $rate = (float) ($config['exchange_rate'] ?? 1);
        if ($rate <= 0) {
            $rate = 1.0;
        }

        $result = $driver->pay($payable, $config);

        Payment::create([
            'order_id' => $main->id,
            'order_ids' => $orders->pluck('id')->all(),
            'channel' => $channel->code,
            'amount' => $payTotal,
            'fee' => $feeFen,
            'status' => 'pending',
            'charged_currency' => $result->currencySent ?? $targetCur,
            'charged_amount' => $result->amountSent ?? $payTotal,
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

        // 聚合支付(order_ids 非空):主订单的支付流水关联了多个订单,统一 markPaid
        $orderNos = Order::whereIn('id', ! empty($payment->order_ids) ? $payment->order_ids : [$order->id])
            ->pluck('order_no')
            ->all();

        // markPaid 内部有 lockForUpdate + 状态检查,并发时第二个会抛异常
        // 捕获后视为幂等(已被其他并发请求处理),返回 success 让第三方停止重试
        foreach ($orderNos as $orderNo) {
            try {
                app(OrderService::class)->markPaid($orderNo);
            } catch (\RuntimeException $e) {
                $fresh = Order::where('order_no', $orderNo)->first();
                if ($fresh && $fresh->status === 'paid') {
                    continue;
                }
                throw $e;
            }
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

        // 标记支付流水 + 充值单为已支付 → 入账余额,全部在同一事务内,
        // 保证原子性(任一步失败整体回滚,避免"已标 paid 但未入账"或"入账成功但回滚状态"的不一致)。
        // 事务返回值:null=并发已处理(幂等直接返回 success),非 null=本次完成转换。
        $converted = DB::transaction(function () use ($recharge, $channelCode, $request) {
            $locked = Recharge::where('id', $recharge->id)->lockForUpdate()->firstOrFail();
            // 并发回调:另一请求已将状态改为 paid → 本次不重复入账
            if ($locked->status !== Recharge::STATUS_PENDING) {
                return null;
            }
            $locked->update(['status' => Recharge::STATUS_PAID, 'paid_at' => now()]);
            Payment::where('recharge_id', $locked->id)->where('channel', $channelCode)
                ->update(['status' => 'success', 'paid_at' => now(), 'raw' => $request->all()]);

            // 入账(同事务):BillService::record 内部 DB::transaction 会退化为保存点,
            // 抛异常时整体回滚(recharge + payment + bill + balance 一起回退)。
            if ($locked->target === Recharge::TARGET_SUPPLY) {
                // 供货余额充值:入账到用户的供货账号(自助 API 对接预存)。
                $supplier = SupplierAccount::where('user_id', $locked->user_id)->firstOrFail();
                $supplier->increment('balance', (int) $locked->amount);
                SupplierLedgerEntry::create([
                    'supplier_account_id' => $supplier->id,
                    'type' => SupplierLedgerEntry::TYPE_RECHARGE,
                    'amount' => (int) $locked->amount,
                    'balance_after' => $supplier->balance,
                    'remark' => __('messages.recharge.credit', ['no' => $locked->recharge_no]),
                ]);
            } else {
                BillService::record(
                    $locked->user_id,
                    (int) $locked->amount,
                    Bill::TYPE_INCOME,
                    __('messages.recharge.credit', ['no' => $locked->recharge_no]),
                );
            }

            return $locked;
        });

        // 并发已处理 → 幂等返回 success(让第三方停止重试)
        if ($converted === null) {
            return 'success';
        }

        return 'success';
    }

    private function resolveDriver(PaymentChannel $channel): PaymentDriver
    {
        $driverClass = $channel->driver;
        if (! class_exists($driverClass)) {
            throw new \RuntimeException("支付 Driver 不存在: {$driverClass}");
        }

        return new $driverClass;
    }

    /**
     * 扫描所有可用支付驱动(代码层面),返回 code → 驱动元信息。
     * 用于后台「添加支付渠道」弹窗:展示系统支持的全部渠道供勾选,
     * 与数据库 payment_channels 表解耦(被删除的渠道也能重新添加)。
     *
     * @return array<int, array{code: string, name: string, driver: string, icon: string}>
     */
    public function discoverDrivers(): array
    {
        $dir = app_path('Payment/Drivers');
        if (! is_dir($dir)) {
            return [];
        }

        $drivers = [];
        foreach (glob($dir.'/*Driver.php') as $file) {
            $className = 'App\\Payment\\Drivers\\'.basename($file, '.php');
            if (! class_exists($className) || ! is_subclass_of($className, PaymentDriver::class)) {
                continue;
            }
            try {
                /** @var PaymentDriver $instance */
                $instance = new $className;
                $info = $instance->getInfo();
                // code 从类名派生(如 AlipayDriver → alipay),与预置 seed 保持一致
                $code = strtolower(str_replace('Driver', '', basename($file, '.php')));
                $drivers[] = [
                    'code' => $code,
                    'name' => $info['name'] ?? $code,
                    'driver' => $className,
                    'icon' => $info['icon'] ?? '💳',
                ];
            } catch (\Throwable) {
                // 驱动实例化失败(如缺依赖)→ 跳过,不影响其他驱动展示
                continue;
            }
        }

        return $drivers;
    }

    /**
     * 检查凭据是否已配置(至少有一个敏感字段非空)。
     * 参考自 acg-faka payCredentialConfigured,防止空 key 伪造签名。
     */
    private function credentialsConfigured(array $config): bool
    {
        $sensitiveKeys = ['key', 'secret', 'secret_key', 'private_key', 'public_key',
            'app_secret', 'api_key', 'api_token', 'client_secret', 'webhook_secret', 'mch_secret_key'];
        foreach ($sensitiveKeys as $k) {
            if (! empty($config[$k])) {
                return true;
            }
        }

        return false;
    }

    public function saveChannelConfig(int $channelId, array $config): void
    {
        PaymentChannel::findOrFail($channelId)->update(['config' => $config]);
    }

    public function toggleChannel(int $channelId, bool $enabled): void
    {
        PaymentChannel::where('id', $channelId)->update(['enabled' => $enabled]);
    }
}
