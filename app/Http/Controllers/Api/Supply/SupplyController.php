<?php

namespace App\Http\Controllers\Api\Supply;

use App\Http\Controllers\Controller;
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

    /** POST /api/supply/callback 接收上游异步回调(本站作为下游时,Phase 3 实现) */
    public function callback(Request $request): JsonResponse
    {
        // Phase 3 实现
        return response()->json(['ok' => true]);
    }
}
