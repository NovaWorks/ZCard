<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Recharge;
use App\Payment\Contracts\Payable;
use App\Support\PaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PaymentController extends Controller
{
    public function channels(PaymentService $service): JsonResponse
    {
        $channels = $service->getEnabledChannels()->map(function ($ch) {
            $driver = app($ch->driver);
            $config = $ch->config ?? [];

            return [
                'id' => $ch->id,
                'name' => $ch->name,
                'code' => $ch->code,
                'icon' => $driver->getInfo()['icon'] ?? '💳',
                'supported_currencies' => $driver->getSupportedCurrencies(),
                'target_currency' => $config['target_currency'] ?? ($driver->getSupportedCurrencies()[0] ?? null),
            ];
        });

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
            $userId = $request->user()?->id;
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
}
