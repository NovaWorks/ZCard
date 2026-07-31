<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Coupon;
use App\Support\CouponService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CouponController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Coupon::query()->with(['product:id,name', 'category:id,name']);

        if ($keyword = $request->input('keyword')) {
            $query->where('code', 'like', "%{$keyword}%")->orWhere('note', 'like', "%{$keyword}%");
        }
        if ($request->has('status') && $request->input('status') !== '') {
            $query->where('status', $request->input('status'));
        }
        if ($request->has('type') && $request->input('type') !== '') {
            $query->where('type', $request->input('type'));
        }
        if ($startDate = $request->input('start_date')) {
            $query->whereDate('created_at', '>=', $startDate);
        }
        if ($endDate = $request->input('end_date')) {
            $query->whereDate('created_at', '<=', $endDate);
        }

        $coupons = $query->orderByDesc('id')->paginate($request->integer('pageSize', 15));
        return response()->json($coupons);
    }

    public function stats(): JsonResponse
    {
        return response()->json([
            'active_count' => Coupon::where('status', 'active')->count(),
            'used_count' => Coupon::where('status', 'used')->count(),
            'disabled_count' => Coupon::where('status', 'disabled')->count(),
            'total_count' => Coupon::count(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'count' => 'required|integer|min:1|max:1000',
            'type' => 'required|string|in:fixed,percent',
            'value' => 'required|integer|min:1',
            'product_id' => 'nullable|integer|exists:products,id',
            'category_id' => 'nullable|integer|exists:categories,id',
            'min_amount' => 'nullable|numeric|min:0',
            'expires_at' => 'nullable|date',
            'note' => 'nullable|string|max:100',
        ]);

        $couponData = [
            'type' => $data['type'],
            'value' => $data['value'],
            'product_id' => $data['product_id'] ?? null,
            'category_id' => $data['category_id'] ?? null,
            'min_amount' => isset($data['min_amount']) ? (int) round($data['min_amount'] * 100) : 0,
            'expires_at' => $data['expires_at'] ?? null,
            'note' => $data['note'] ?? '',
        ];

        try {
            $coupons = CouponService::generate($data['count'], $couponData);
            return response()->json(['count' => count($coupons), 'codes' => array_map(fn ($c) => $c->code, $coupons)], 201);
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 400);
        }
    }

    public function toggle(int $id): JsonResponse
    {
        try {
            return response()->json(CouponService::toggle($id));
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 400);
        }
    }

    public function destroy(int $id): JsonResponse
    {
        $coupon = Coupon::findOrFail($id);
        if ($coupon->status === Coupon::STATUS_USED) {
            return response()->json(['message' => '已使用的优惠券不能删除'], 422);
        }
        $coupon->delete();
        return response()->json(null, 204);
    }
}
