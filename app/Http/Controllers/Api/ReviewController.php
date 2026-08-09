<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Product;
use App\Models\Review;
use App\Support\ReviewService;
use App\Support\StorefrontConfig;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReviewController extends Controller
{
    /**
     * 提交评价 POST /api/reviews(需登录)。
     */
    public function store(Request $request, ReviewService $service): JsonResponse
    {
        $data = $request->validate([
            'product_id' => 'required|integer|exists:products,id',
            'order_id' => 'required|integer|exists:orders,id',
            'rating' => 'required|integer|min:1|max:5',
            'content' => 'nullable|string|max:1000',
        ]);

        $user = $request->user();

        try {
            $review = $service->createReview(
                $user->id,
                $data['product_id'],
                $data['order_id'],
                $data['rating'],
                $data['content'] ?? null,
            );

            return response()->json([
                'id' => $review->id,
                'status' => $review->status,
            ], 201);
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 400);
        }
    }

    /**
     * 商品评价列表 GET /api/products/{slug}/reviews。
     * 返回综合评分 + 合并评论列表(真实 + 虚拟)。
     */
    public function productReviews(string $slug, ReviewService $service): JsonResponse
    {
        $product = Product::where('slug', $slug)->firstOrFail();

        $data = $service->getProductRating($product->id, $product->virtual_reviews);

        return response()->json($data);
    }

    /**
     * 当前用户对该商品的可评价状态 GET /api/reviews/eligibility/{productId}(需登录)。
     * 供前端决定是否展示「写评价」入口。
     */
    public function eligibility(Request $request, int $productId): JsonResponse
    {
        $user = $request->user();
        $enabled = (bool) StorefrontConfig::get('allow_post_review');
        $reviewed = Review::where('user_id', $user->id)
            ->where('product_id', $productId)
            ->exists();

        // 该用户该商品的已支付且未评价订单(取最近一笔,作为评价挂靠的订单)
        $orderId = null;
        if ($enabled && ! $reviewed) {
            $orderId = Order::where('user_id', $user->id)
                ->where('product_id', $productId)
                ->where('status', 'paid')
                ->whereDoesntHave('reviews')
                ->orderByDesc('id')
                ->value('id');
        }

        return response()->json([
            'allow_post_review' => $enabled,
            'can_review' => $enabled && ! $reviewed && $orderId !== null,
            'reviewed' => $reviewed,
            'order_id' => $orderId,
        ]);
    }
}
