<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\InsufficientStockException;
use App\Http\Controllers\Controller;
use App\Support\CaptchaService;
use App\Support\OrderService;
use App\Support\StorefrontConfig;
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
            'card_id' => 'nullable|integer', // 靓号自选:客户选定的具体卡密
            'contact' => 'required|string|max:150',
            'password' => 'nullable|string|max:50',
            'captcha' => 'nullable|string',
            'coupon_code' => 'nullable|string|max:32',
            'extra' => 'nullable|array',
            'display_currency' => 'nullable|string|size:3',
        ]);

        // 游客下单限制
        $guestCheckout = StorefrontConfig::get('guest_checkout') ?? true;
        if (! $guestCheckout && ! $request->user()) {
            return response()->json(['message' => __('messages.guest_only')], 403);
        }

        // 下单验证码校验
        if (CaptchaService::isEnabled('trade')) {
            if (! CaptchaService::verify('trade', $data['captcha'] ?? null)) {
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
                    'card_id' => $data['card_id'] ?? null,
                    'user_id' => $request->user()?->id,
                    'create_ip' => $request->ip(),
                    'create_device' => $this->detectDevice($request),
                ],
                $data['display_currency'] ?? $request->attributes->get('currency'),
            );

            return response()->json([
                'order_no' => $order->order_no,
                'amount' => $order->amount,
                'status' => $order->status,
            ], 201);
        } catch (InsufficientStockException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    /** 从 User-Agent 检测下单设备 */
    private function detectDevice(Request $request): string
    {
        $ua = strtolower($request->userAgent() ?: '');
        if (str_contains($ua, 'windows')) {
            return 'win';
        }
        if (str_contains($ua, 'mac') || str_contains($ua, 'macintosh')) {
            return 'mac';
        }
        if (preg_match('/(iphone|ipad|ipod)/', $ua)) {
            return 'ios';
        }
        if (str_contains($ua, 'android')) {
            return 'android';
        }

        return 'other';
    }

    /** 购物车批量下单:多个商品各创建一张订单(一个事务,任一失败整体回滚) */
    public function batch(Request $request, OrderService $service): JsonResponse
    {
        $data = $request->validate([
            'items' => 'required|array|min:1|max:20',
            'items.*.product_id' => 'required|integer|exists:products,id',
            'items.*.sku_id' => 'nullable|integer',
            'items.*.qty' => 'required|integer|min:1|max:100',
            'items.*.card_id' => 'nullable|integer', // 靓号自选:该商品项选定的卡密
            'contact' => 'required|string|max:150',
            'password' => 'nullable|string|max:50',
            'captcha' => 'nullable|string',
            'coupon_code' => 'nullable|string|max:32',
            'extra' => 'nullable|array',
            'display_currency' => 'nullable|string|size:3',
        ]);

        $guestCheckout = StorefrontConfig::get('guest_checkout') ?? true;
        if (! $guestCheckout && ! $request->user()) {
            return response()->json(['message' => __('messages.guest_only')], 403);
        }

        if (CaptchaService::isEnabled('trade')) {
            if (! CaptchaService::verify('trade', $data['captcha'] ?? null)) {
                return response()->json(['message' => __('messages.captcha_error')], 422);
            }
        }

        try {
            $orders = $service->batchCreate(
                $data['items'],
                [
                    'contact' => $data['contact'],
                    'password' => $data['password'] ?? null,
                    'extra' => $data['extra'] ?? null,
                    'coupon_code' => $data['coupon_code'] ?? null,
                    'user_id' => $request->user()?->id,
                    'create_ip' => $request->ip(),
                    'create_device' => $this->detectDevice($request),
                ],
                $data['display_currency'] ?? $request->attributes->get('currency'),
            );

            return response()->json([
                'orders' => $orders->map(fn ($o) => [
                    'id' => $o->id,
                    'order_no' => $o->order_no,
                    'product_id' => $o->product_id,
                    'amount' => $o->amount,
                    'discount_amount' => $o->discount_amount,
                    'status' => $o->status,
                ]),
                'total_amount' => (int) $orders->sum('amount'),
                'order_ids' => $orders->pluck('id')->all(),
            ], 201);
        } catch (InsufficientStockException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function mockPay(string $orderNo, OrderService $service): JsonResponse
    {
        // 安全:模拟支付仅限开发/测试环境,生产环境禁用(否则任何人可白嫖订单+触发佣金)
        if (! app()->environment('local', 'testing')) {
            return response()->json(['message' => 'Not available'], 404);
        }

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

        // 未找到返回 200 + 空数组,让前端走空状态(而非把 404 当错误处理导致页面空白)
        return response()->json($orders ?? []);
    }

    public function myOrders(Request $request, OrderService $service): JsonResponse
    {
        return response()->json($service->myOrders($request->user()->id));
    }
}
