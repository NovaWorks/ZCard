<?php

namespace App\Http\Controllers\Api\Supply;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * 供货 API 订单控制器(spec §4.4) —— 下游下单拿货
 */
class SupplyOrderController extends Controller
{
    public function create(Request $request): JsonResponse
    {
        return response()->json(['ok' => true]);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        return response()->json(['ok' => true]);
    }

    public function cancel(Request $request, int $id): JsonResponse
    {
        return response()->json(['ok' => true]);
    }
}
