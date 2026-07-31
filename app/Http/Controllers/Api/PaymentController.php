<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
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

    public function create(Request $request, PaymentService $service): JsonResponse
    {
        $data = $request->validate([
            'order_no' => 'required|string|exists:orders,order_no',
            'channel_id' => 'required|integer|exists:payment_channels,id',
        ]);

        $order = Order::where('order_no', $data['order_no'])->firstOrFail();

        if ($order->status !== 'pending') {
            return response()->json(['message' => __('messages.order.status_abnormal')], 400);
        }

        try {
            $result = $service->createPayment($order, $data['channel_id']);
            return response()->json($result);
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 500);
        }
    }

    public function callback(string $channel, Request $request, PaymentService $service)
    {
        $result = $service->handleCallback($channel, $request);
        return response($result === 'success' ? 'success' : $result);
    }
}
