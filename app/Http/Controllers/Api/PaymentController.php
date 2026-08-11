<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Bill;
use App\Models\Order;
use App\Models\Recharge;
use App\Models\User;
use App\Payment\Contracts\Payable;
use App\Support\BillService;
use App\Support\OrderService;
use App\Support\PaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PaymentController extends Controller
{
    public function channels(PaymentService $service, Request $request): JsonResponse
    {
        $channels = $service->getEnabledChannels()->map(function ($ch) {
            $driver = app($ch->driver);
            $config = $ch->config ?? [];

            return [
                'id' => $ch->id,
                'name' => $ch->name,
                'code' => $ch->code,
                'icon' => $driver->getInfo()['icon'] ?? '💳',
                'pay_types' => $driver->getPayTypes($config),
                'supported_currencies' => $driver->getSupportedCurrencies(),
                'target_currency' => $config['target_currency'] ?? ($driver->getSupportedCurrencies()[0] ?? null),
                // 手续费配置(供前端展示明细:客户承担时原价+手续费=应付)
                'fee' => (float) ($ch->fee ?? 0),
                'fee_type' => $ch->fee_type ?? 'percent',
                'fee_bearer' => $ch->fee_bearer ?? 'merchant',
            ];
        });

        // 余额支付(登录用户可见,不占支付通道表;id=0 与库表通道区分)
        if ($user = $this->activeUser($request)) {
            $channels->push([
                'id' => 0,
                'name' => '余额支付',
                'code' => 'balance',
                'icon' => 'ri:wallet-3-line',
                'pay_types' => ['balance'],
                'supported_currencies' => [],
                'target_currency' => null,
                'fee' => 0,
                'fee_type' => 'percent',
                'fee_bearer' => 'merchant',
                'balance' => (int) $user->balance,
            ]);
        }

        return response()->json($channels);
    }

    /**
     * 创建支付:兼容发卡订单(ORD)与充值单(RCH)。
     * 两者都实现 Payable,交由 PaymentService::createPayment 统一处理。
     */
    public function create(Request $request, PaymentService $service): JsonResponse
    {
        $data = $request->validate([
            'order_no' => 'required|string',
            'channel_id' => 'required|integer|exists:payment_channels,id',
        ]);

        $bizNo = $data['order_no'];

        // 按单号前缀解析业务对象(订单 / 充值单)
        if (str_starts_with($bizNo, 'RCH')) {
            // 充值单强归属:必须登录,且只能为本人充值单发起支付(防越权)
            // 注意:本路由不在 auth:sanctum 组内,$request->user() 默认走 web guard(session),
            // 无法解析 Bearer token → 恒为 null。必须显式用 sanctum guard 解析。
            $userId = $this->activeUser($request)?->id;
            if (! $userId) {
                return response()->json(['message' => __('messages.recharge.login_required')], 401);
            }
            $payable = Recharge::where('recharge_no', $bizNo)->where('user_id', $userId)->first();
            if (! $payable) {
                return response()->json(['message' => __('messages.recharge.not_found')], 404);
            }
            if ($payable->status !== Recharge::STATUS_PENDING) {
                return response()->json(['message' => __('messages.order.status_abnormal')], 400);
            }
        } else {
            $payable = Order::where('order_no', $bizNo)->firstOrFail();
            if ($payable->status !== 'pending') {
                return response()->json(['message' => __('messages.order.status_abnormal')], 400);
            }
        }

        try {
            $result = $service->createPayment($payable, $data['channel_id']);

            return response()->json($result);
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 500);
        }
    }

    /**
     * 购物车聚合支付:一次支付多个待支付订单(收银台场景)。
     */
    public function batchCreate(Request $request, PaymentService $service): JsonResponse
    {
        $data = $request->validate([
            'order_ids' => 'required|array|min:1',
            'order_ids.*' => 'integer',
            'channel_id' => 'required|integer|exists:payment_channels,id',
        ]);

        try {
            $result = $service->createBatchPayment($data['order_ids'], $data['channel_id']);

            return response()->json($result);
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 400);
        }
    }

    public function callback(string $channel, Request $request, PaymentService $service)
    {
        $result = $service->handleCallback($channel, $request);

        return response($result === 'success' ? 'success' : $result);
    }

    /**
     * 余额支付单个订单:校验归属 → 余额扣款 → 标记支付(触发发货/佣金/升级)。
     * 订单写入 payment_channel=balance,在后台订单管理中正常可见。
     */
    public function balancePay(Request $request, OrderService $orderService): JsonResponse
    {
        $userId = $this->activeUser($request)?->id;
        if (! $userId) {
            return response()->json(['message' => __('messages.recharge.login_required')], 401);
        }
        $data = $request->validate(['order_no' => 'required|string']);

        try {
            $result = DB::transaction(function () use ($data, $userId, $orderService) {
                $order = Order::where('order_no', $data['order_no'])->lockForUpdate()->firstOrFail();

                return $this->settleOrders(collect([$order]), $userId, $orderService);
            });

            return response()->json($result);
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 400);
        }
    }

    /**
     * 购物车聚合余额支付:一次支付多个待支付订单(逐单校验归属/状态,总额一次扣款)。
     */
    public function balanceBatchPay(Request $request, OrderService $orderService): JsonResponse
    {
        $userId = $this->activeUser($request)?->id;
        if (! $userId) {
            return response()->json(['message' => __('messages.recharge.login_required')], 401);
        }
        $data = $request->validate([
            'order_ids' => 'required|array|min:1',
            'order_ids.*' => 'integer',
        ]);

        try {
            $result = DB::transaction(function () use ($data, $userId, $orderService) {
                $orders = Order::whereIn('id', $data['order_ids'])
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get();
                if ($orders->count() !== count($data['order_ids'])) {
                    throw new \RuntimeException('订单不存在');
                }

                return $this->settleOrders($orders, $userId, $orderService);
            });

            return response()->json($result);
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 400);
        }
    }

    /**
     * 余额结算公共逻辑:校验归属与状态 → 总额扣款 → 逐单标记支付。
     */
    private function settleOrders($orders, int $userId, OrderService $orderService): array
    {
        $total = 0;
        foreach ($orders as $order) {
            if ($order->user_id !== $userId) {
                throw new \RuntimeException('无权支付该订单');
            }
            if ($order->status !== 'pending') {
                throw new \RuntimeException("订单状态异常: {$order->order_no}");
            }
            $total += (int) $order->amount;
        }

        // 余额扣款(BillService 内部行锁 + 余额不足校验 + 流水)
        BillService::record(
            $userId,
            $total,
            Bill::TYPE_EXPENSE,
            '余额支付订单 '.$orders->pluck('order_no')->implode(','),
            $orders->first()->id,
        );

        foreach ($orders as $order) {
            // 预写支付渠道,markPaid 内会保留该值(无第三方 Payment 记录)
            $order->update(['payment_channel' => 'balance']);
            $orderService->markPaid($order->order_no);
        }

        return [
            'orders' => $orders->map(fn ($o) => [
                'order_no' => $o->order_no,
                'status' => 'paid',
                'delivered' => $o->fresh()->orderDeliveries()->count() > 0,
            ])->all(),
            'amount' => $total,
            'balance_after' => (int) User::find($userId)?->balance,
        ];
    }

    private function activeUser(Request $request): ?User
    {
        $user = $request->user('sanctum');

        return $user && (int) $user->status === 1 ? $user : null;
    }
}
