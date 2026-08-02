<?php

namespace App\Http\Controllers\Api\Supply;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * 供货 API 商品控制器(spec §4.3) —— 下游查询商品/库存
 */
class SupplyProductController extends Controller
{
    public function categories(Request $request): JsonResponse
    {
        return response()->json(['ok' => true, 'categories' => []]);
    }

    public function index(Request $request): JsonResponse
    {
        return response()->json(['ok' => true, 'items' => []]);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        return response()->json(['ok' => true]);
    }

    public function stock(Request $request, int $id): JsonResponse
    {
        return response()->json(['ok' => true]);
    }
}
