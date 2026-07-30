<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Support\OrderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * 后台订单管理。列表 + 详情 + 手动关单。
 * 创建/支付走前台,这里只读 + 关单(spec §7.x)。
 */
class OrderController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Order::query()
            ->with('product:id,name')
            ->withCount('orderDeliveries');

        if ($status = $request->input('status')) {
            $query->where('status', $status);
        }

        if ($search = $request->input('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('order_no', 'like', "%{$search}%")
                  ->orWhere('contact', 'like', "%{$search}%")
                  ->orWhereHas('product', fn ($p) => $p->where('name', 'like', "%{$search}%"));
            });
        }

        $orders = $query->orderByDesc('id')
            ->paginate($request->integer('pageSize', 15));

        return response()->json($orders);
    }

    public function show(int $id): JsonResponse
    {
        $order = Order::with(['product:id,name', 'orderDeliveries:id,order_id,card_content,delivered_mode,delivered_at'])
            ->findOrFail($id);

        return response()->json($order);
    }

    public function close(int $id): JsonResponse
    {
        $order = app(OrderService::class)->closeOrder($id);

        return response()->json($order);
    }
}
