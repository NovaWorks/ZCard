<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\CouponService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CouponController extends Controller
{
    /**
     * 验证优惠券(下单前预览折扣)。
     * POST /api/coupons/validate
     */
    public function validateCode(Request $request): JsonResponse
    {
        $data = $request->validate([
            'code' => 'required|string|max:32',
            'product_id' => 'required|integer|exists:products,id',
            'amount' => 'required|integer|min:0',
        ]);

        // amount 直接是分(前端传 price * qty),无需换算
        $amountFen = (int) $data['amount'];

        try {
            $result = CouponService::validate($data['code'], $data['product_id'], $amountFen);
            return response()->json([
                'valid' => true,
                'discount' => $result['discount'],
                'discount_display' => number_format($result['discount'] / 100, 2),
                'final_amount' => $amountFen - $result['discount'],
                'final_display' => number_format(($amountFen - $result['discount']) / 100, 2),
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'valid' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }
}
