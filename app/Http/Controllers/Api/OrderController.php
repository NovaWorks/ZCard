<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\OrderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OrderController extends Controller
{
    public function create(Request $request, OrderService $service): JsonResponse
    {
        $data = $request->validate([
            'product_id' => 'required|integer|exists:products,id',
            'sku_id' => 'nullable|integer',
            'qty' => 'required|integer|min:1|max:100',
            'contact' => 'required|string|max:150',
            'password' => 'nullable|string|max:50',
            'captcha' => 'nullable|string',
            'coupon_code' => 'nullable|string|max:32',
            'extra' => 'nullable|array',
            'display_currency' => 'nullable|string|size:3',
        ]);

        // 游客下单限制
        $guestCheckout = \App\Support\StorefrontConfig::get('guest_checkout') ?? true;
        if (! $guestCheckout && ! $request->user()) {
            return response()->json(['message' => __('messages.guest_only')], 403);
        }

        // 下单验证码校验
        if (\App\Support\CaptchaService::isEnabled('trade')) {
            if (! \App\Support\CaptchaService::verify('trade', $data['captcha'] ?? null)) {
                return response()->json(['message' => __('messages.captcha_error')], 422);
            }
        }

        try {
            $order = $service->createOrder(
                $data['product_id'],
                $data['sku_id'] ?? null,
                $data['qty'],
                [
                    'contact' => $data['contact'],
                    'password' => $data['password'] ?? null,
                    'extra' => $data['extra'] ?? null,
                    'coupon_code' => $data['coupon_code'] ?? null,
                    'user_id' => $request->user()?->id,
                    'create_ip' => $request->ip(),
                    'create_device' => $this->detectDevice($request),
                ],
                $data['display_currency'] ?? null,
            );

            return response()->json([
                'order_no' => $order->order_no,
                'amount' => $order->amount,
                'status' => $order->status,
            ], 201);
        } catch (\App\Exceptions\InsufficientStockException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    /** 从 User-Agent 检测下单设备 */
    private function detectDevice(Request $request): string
    {
        $ua = strtolower($request->userAgent() ?: '');
        if (str_contains($ua, 'windows')) return 'win';
        if (str_contains($ua, 'mac') || str_contains($ua, 'macintosh')) return 'mac';
        if (preg_match('/(iphone|ipad|ipod)/', $ua)) return 'ios';
        if (str_contains($ua, 'android')) return 'android';
        return 'other';
    }

    public function mockPay(string $orderNo, OrderService $service): JsonResponse
    {
        try {
            $order = $service->markPaid($orderNo);

            return response()->json([
                'order_no' => $order->order_no,
                'status' => $order->status,
                'delivered' => $order->fresh()->orderDeliveries()->count() > 0,
            ]);
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 400);
        }
    }

    public function query(Request $request, OrderService $service): JsonResponse
    {
        $data = $request->validate([
            'keyword' => 'required|string|max:150',
            'password' => 'nullable|string|max:50',
        ]);

        $orders = $service->searchOrders($data['keyword'], $data['password'] ?? null);

        if (! $orders) {
            return response()->json(['message' => __('messages.order_not_found')], 404);
        }

        return response()->json($orders);
    }

    public function myOrders(Request $request, OrderService $service): JsonResponse
    {
        return response()->json($service->myOrders($request->user()->id));
    }
}
