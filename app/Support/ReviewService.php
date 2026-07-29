<?php

namespace App\Support;

use App\Models\Review;
use Illuminate\Support\Facades\DB;

class ReviewService
{
    /**
     * 创建评价。
     * @throws \RuntimeException 当订单不存在/未支付/已评价
     */
    public function createReview(int $userId, int $productId, int $orderId, int $rating, ?string $content): Review
    {
        // 检查订单是否已支付且属于该用户
        $order = \App\Models\Order::where('id', $orderId)
            ->where('user_id', $userId)
            ->where('product_id', $productId)
            ->where('status', 'paid')
            ->first();

        if (! $order) {
            throw new \RuntimeException('订单不存在或未支付');
        }

        // 检查是否已评价(unique 约束兜底)
        $exists = Review::where('order_id', $orderId)->where('product_id', $productId)->exists();
        if ($exists) {
            throw new \RuntimeException('该订单已评价');
        }

        $needAudit = StorefrontConfig::get('review_need_audit');

        return Review::create([
            'product_id' => $productId,
            'user_id' => $userId,
            'order_id' => $orderId,
            'rating' => max(1, min(5, $rating)),
            'content' => $content,
            'status' => $needAudit ? Review::STATUS_PENDING : Review::STATUS_APPROVED,
        ]);
    }

    /** 取商品已审核评价 */
    public function getApprovedReviews(int $productId): array
    {
        return Review::where('product_id', $productId)
            ->where('status', Review::STATUS_APPROVED)
            ->with('user:id,username')
            ->orderByDesc('id')
            ->limit(50)
            ->get()
            ->map(fn ($r) => [
                'id' => $r->id,
                'name' => $r->user?->username ?? '匿名用户',
                'rating' => $r->rating,
                'content' => $r->content,
                'created_at' => $r->created_at?->toDateString(),
            ])
            ->toArray();
    }

    /**
     * 综合评分 + 评论数(真实 + 虚拟合并)。
     * @return array{rating: float, count: int, list: array}
     */
    public function getProductRating(int $productId, ?array $virtualReviews = null): array
    {
        $approved = $this->getApprovedReviews($productId);

        // 真实评分
        $realRating = Review::where('product_id', $productId)
            ->where('status', Review::STATUS_APPROVED)
            ->avg('rating');
        $realCount = count($approved);

        // 虚拟评分
        $virtualRating = $virtualReviews['rating'] ?? null;
        $virtualCount = $virtualReviews['count'] ?? 0;
        $virtualList = $virtualReviews['list'] ?? [];

        // 合并
        $totalCount = $realCount + $virtualCount;
        $mergedRating = 0;
        if ($totalCount > 0) {
            $sum = ($realRating ? $realRating * $realCount : 0) + ($virtualRating ? $virtualRating * $virtualCount : 0);
            $mergedRating = round($sum / $totalCount, 1);
        }

        // 合并列表(真实在前,虚拟在后)
        $list = array_merge($approved, array_map(fn ($v) => [
            'id' => 'v-' . ($v['name'] ?? 'x'),
            'name' => $v['name'] ?? '匿名用户',
            'rating' => $v['rating'] ?? 5,
            'content' => $v['content'] ?? '',
            'created_at' => null,
        ], $virtualList));

        return [
            'rating' => $mergedRating ?: ($virtualRating ?? 0),
            'count' => $totalCount,
            'list' => $list,
        ];
    }

    /** 审核通过 */
    public function approveReview(int $id): void
    {
        Review::where('id', $id)->update(['status' => Review::STATUS_APPROVED]);
    }

    /** 审核拒绝 */
    public function rejectReview(int $id): void
    {
        Review::where('id', $id)->update(['status' => Review::STATUS_REJECTED]);
    }
}
