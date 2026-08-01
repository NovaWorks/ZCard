<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Review;
use App\Support\ReviewService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * 评价后台管理(审核/拒绝/列表)。#1 评价审核页后端。
 */
class ReviewController extends Controller
{
    /** 评价列表(带筛选) */
    public function index(Request $request): JsonResponse
    {
        $query = Review::query()
            ->with(['product:id,name,slug', 'user:id,username']);

        if ($status = $request->input('status')) {
            $query->where('status', $status);
        }
        if ($productId = $request->input('product_id')) {
            $query->where('product_id', $productId);
        }

        $pageSize = (int) ($request->input('page_size', 20));
        $reviews = $query->orderByDesc('id')->paginate($pageSize);

        return response()->json($reviews);
    }

    /** 统计 */
    public function stats(): JsonResponse
    {
        return response()->json([
            'total' => Review::count(),
            'pending' => Review::where('status', Review::STATUS_PENDING)->count(),
            'approved' => Review::where('status', Review::STATUS_APPROVED)->count(),
            'rejected' => Review::where('status', Review::STATUS_REJECTED)->count(),
        ]);
    }

    /** 审核通过 */
    public function approve(int $id): JsonResponse
    {
        app(ReviewService::class)->approveReview($id);
        return response()->json(['message' => 'ok']);
    }

    /** 审核拒绝 */
    public function reject(int $id): JsonResponse
    {
        app(ReviewService::class)->rejectReview($id);
        return response()->json(['message' => 'ok']);
    }
}
