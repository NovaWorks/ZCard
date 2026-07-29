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
            'extra' => 'nullable|array',
        ]);

        // 验证码校验(若开关开)— P1-C 暂跳过,mews/captcha 集成后补

        try {
            $order = $service->createOrder(
                $data['product_id'],
                $data['sku_id'] ?? null,
                $data['qty'],
                [
                    'contact' => $data['contact'],
                    'password' => $data['password'] ?? null,
                    'extra' => $data['extra'] ?? null,
                ]
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
            'contact' => 'required|string',
            'order_no' => 'required|string',
            'password' => 'nullable|string',
        ]);

        $order = $service->queryOrder($data['contact'], $data['order_no'], $data['password'] ?? null);

        if (! $order) {
            return response()->json(['message' => '未找到订单,请检查邮箱和订单号'], 404);
        }

        return response()->json($service->getOrderDetail($order));
    }

    public function myOrders(Request $request, OrderService $service): JsonResponse
    {
        return response()->json($service->myOrders($request->user()->id));
    }
}
