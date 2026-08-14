<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\InsufficientStockException;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\CaptchaService;
use App\Support\OrderService;
use App\Support\StorefrontConfig;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

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
            'password' => 'nullable|string|min:6|max:50',
            'captcha' => 'nullable|string',
            'captcha_key' => 'nullable|string|max:255',
            'coupon_code' => 'nullable|string|max:32',
            'extra' => 'nullable|array',
            'display_currency' => 'nullable|string|size:3',
        ]);

        // 游客下单限制
        // 注意:本路由不在 auth:sanctum 组内,$request->user() 默认走 web guard(session),
        // 无法解析 storefront 发送的 Bearer token → 恒为 null(登录用户也被当游客)。
        // 必须显式用 sanctum guard 解析,否则订单 user_id 恒为空,"我的订单"查不到。
        $guestCheckout = StorefrontConfig::get('guest_checkout') ?? true;
        $user = $this->activeUser($request);
        if (! $guestCheckout && ! $user) {
            return response()->json(['message' => __('messages.guest_only')], 403);
        }

        // 下单验证码校验
        if (CaptchaService::isEnabled('trade')) {
            if (! CaptchaService::verify('trade', $data['captcha'] ?? null, $data['captcha_key'] ?? null)) {
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
                    'user_id' => $user?->id,
                    'create_ip' => $request->ip(),
                    'create_device' => $this->detectDevice($request),
                ],
                $data['display_currency'] ?? $request->attributes->get('currency'),
            );

            return response()->json([
                'order_no' => $order->order_no,
                'amount' => $order->amount,
                'status' => $order->status,
                'access_token' => $order->accessTokenForResponse(),
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
            'password' => 'nullable|string|min:6|max:50',
            'captcha' => 'nullable|string',
            'captcha_key' => 'nullable|string|max:255',
            'coupon_code' => 'nullable|string|max:32',
            'extra' => 'nullable|array',
            'display_currency' => 'nullable|string|size:3',
        ]);

        $guestCheckout = StorefrontConfig::get('guest_checkout') ?? true;
        $user = $this->activeUser($request);
        if (! $guestCheckout && ! $user) {
            return response()->json(['message' => __('messages.guest_only')], 403);
        }

        if (CaptchaService::isEnabled('trade')) {
            if (! CaptchaService::verify('trade', $data['captcha'] ?? null, $data['captcha_key'] ?? null)) {
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
                    'user_id' => $user?->id,
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
                    'access_token' => $o->accessTokenForResponse(),
                ]),
                'total_amount' => (int) $orders->sum('amount'),
                'order_ids' => $orders->pluck('id')->all(),
            ], 201);
        } catch (InsufficientStockException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            // 安全(M-16):不向客户端回显内部异常细节,统一文案 + 服务端日志。
            Log::error('批量下单失败', ['exception' => $e]);

            return response()->json(['message' => __('messages.order.create_failed')], 422);
        }
    }

    public function mockPay(string $orderNo, OrderService $service): JsonResponse
    {
        // 安全:模拟支付必须显式开启(ZCARD_ALLOW_MOCK_PAYMENT=true)且绝不允许在生产环境生效
        // (否则任何人可白嫖订单+触发佣金)。不依赖 APP_ENV=testing 这类易误配的判断。
        if (app()->environment('production') || ! config('zcard.allow_mock_payment', false)) {
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

    private function activeUser(Request $request): ?User
    {
        $user = $request->user('sanctum');

        return $user && (int) $user->status === 1 ? $user : null;
    }

    public function query(Request $request, OrderService $service): JsonResponse
    {
        $data = $request->validate([
            'keyword' => 'required|string|max:150',
            'password' => 'nullable|string|max:50',
            'access_token' => 'nullable|string|size:64',
        ]);

        // 安全(M-9→低危加强):按关键字(不含 IP)计数锁定——纯 IP 维度可被代理池重置;
        // 同关键字 5 次"命中但未授权"锁 15 分钟,阻止对查单密码的在线爆破。
        $failKey = 'order_query_fail:'.hash('sha256', trim($data['keyword']));
        if ((int) cache()->get($failKey, 0) >= 5) {
            return response()->json(['message' => __('messages.order.query_locked')], 429);
        }

        $result = $service->searchOrders(
            $data['keyword'],
            $data['password'] ?? null,
            $data['access_token'] ?? null,
            $this->activeUser($request)?->id,
        );

        $orders = $result['orders'];
        if ($orders === [] && $result['matched'] > 0) {
            // 命中订单但全部未通过授权 = 疑似爆破尝试。
            cache()->put($failKey, (int) cache()->get($failKey, 0) + 1, 900);
        } elseif ($orders !== []) {
            cache()->forget($failKey);
        }

        // 未找到返回 200 + 空数组,让前端走空状态(而非把 404 当错误处理导致页面空白)
        return response()->json($orders);
    }

    public function myOrders(Request $request, OrderService $service): JsonResponse
    {
        return response()->json($service->myOrders($request->user()->id));
    }
}
