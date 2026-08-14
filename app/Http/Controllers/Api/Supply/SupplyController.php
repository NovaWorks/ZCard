<?php

namespace App\Http\Controllers\Api\Supply;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\SupplySource;
use App\Supply\SupplyManager;
use App\Supply\UpstreamOrderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * 供货 API 主控制器(spec §4.3)
 * /api/supply/*  对外供货,被下游系统(含另一个ZCard)调用。
 */
class SupplyController extends Controller
{
    /** POST /api/supply/ping 测连通+返回余额 */
    public function ping(Request $request): JsonResponse
    {
        $account = $request->attributes->get('supplier_account');

        return response()->json([
            'ok' => true,
            'name' => $account->name,
            'balance' => (int) $account->balance,
            'currency' => config('app.currency', 'CNY'),
        ]);
    }

    /** POST /api/supply/callback 接收上游异步发货回调(本站作为下游时) */
    public function callback(Request $request): JsonResponse
    {
        // 本站作为下游时,接收上游异步发货回调(spec §5.3)
        $orderNo = $request->input('downstream_order_no');
        $order = $orderNo ? Order::where('order_no', $orderNo)->first() : null;

        // 安全(低危):查单失败与验签失败统一返回 401 invalid_signature,
        // 消除「404=单号存在 / 401=单号不存在」的订单号存在性枚举 oracle。
        if (! $order || ! $order->upstream_source_id) {
            return response()->json(['ok' => false, 'error' => 'invalid_signature'], 401);
        }

        $source = SupplySource::find($order->upstream_source_id);
        $driver = app(SupplyManager::class)->driver($source);
        $payload = $driver->verifyCallback($request);

        if (! $payload) {
            return response()->json(['ok' => false, 'error' => 'invalid_signature'], 401);
        }

        // 一致性校验(低危):回调声明的上游单号必须与本地登记的 upstream_order_id 一致,
        // 防止被攻破/有 bug 的上游把 A 单的卡密写到 B 单。
        $upstreamId = (string) ($payload['upstream_order_id'] ?? '');
        if ($upstreamId !== '' && $upstreamId !== (string) $order->upstream_order_id) {
            return response()->json(['ok' => false, 'error' => 'invalid_signature'], 401);
        }

        if (($payload['status'] ?? '') === 'delivered' || ! empty($payload['cards'])) {
            app(UpstreamOrderService::class)->writeFulfillment(
                $order,
                $payload['cards'] ?? [],
                $payload['instructions'] ?? null,
            );
        }

        return response()->json(['ok' => true]);
    }
}
