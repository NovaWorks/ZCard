<?php

namespace App\Support;

use App\Models\Coupon;
use App\Models\Product;
use Illuminate\Support\Str;

/**
 * 优惠券服务:生成/校验/核销。
 */
class CouponService
{
    /**
     * 批量生成优惠券。
     *
     * @return Coupon[] 生成的优惠券列表
     */
    public static function generate(int $count, array $data): array
    {
        if ($count < 1 || $count > 1000) {
            throw new \RuntimeException('生成数量须在 1-1000 之间');
        }

        $coupons = [];
        for ($i = 0; $i < $count; $i++) {
            $code = strtoupper(Str::random(12));
            $coupons[] = Coupon::create(array_merge($data, [
                'code' => $code,
                'status' => Coupon::STATUS_ACTIVE,
            ]));
        }

        return $coupons;
    }

    /**
     * 校验优惠券是否可用,返回折扣信息或抛异常。
     *
     * @param  string  $code  券码
     * @param  int  $productId  商品ID
     * @param  int  $amountFen  订单金额(分)
     * @return array{discount: int, coupon: Coupon}
     */
    public static function validate(string $code, int $productId, int $amountFen): array
    {
        $coupon = Coupon::where('code', $code)->first();
        if (! $coupon) {
            throw new \RuntimeException(__('messages.coupon.not_found'));
        }
        if ($coupon->status !== Coupon::STATUS_ACTIVE) {
            throw new \RuntimeException(__('messages.coupon.invalid'));
        }
        if ($coupon->expires_at && $coupon->expires_at->isPast()) {
            throw new \RuntimeException(__('messages.coupon.expired'));
        }

        // 适用商品校验
        if ($coupon->product_id && $coupon->product_id !== $productId) {
            throw new \RuntimeException(__('messages.coupon.not_for_product'));
        }

        // 适用分类校验
        if ($coupon->category_id) {
            $product = Product::find($productId);
            if (! $product || $product->category_id !== $coupon->category_id) {
                throw new \RuntimeException(__('messages.coupon.not_for_category'));
            }
        }

        // 最低消费校验
        if ($coupon->min_amount > 0 && $amountFen < $coupon->min_amount) {
            throw new \RuntimeException(__('messages.coupon.below_min'));
        }

        // 计算折扣
        $discount = self::calculateDiscount($coupon, $amountFen);

        // 折扣不能超过订单金额
        if ($discount >= $amountFen) {
            throw new \RuntimeException(__('messages.coupon.exceeds_amount'));
        }

        return ['discount' => $discount, 'coupon' => $coupon];
    }

    /**
     * 计算折扣金额(分)。
     */
    public static function calculateDiscount(Coupon $coupon, int $amountFen): int
    {
        if ($coupon->type === Coupon::TYPE_FIXED) {
            return $coupon->value; // 固定金额(分)
        }

        // percent: value=10 表示减 10%
        return (int) floor($amountFen * $coupon->value / 100);
    }

    /**
     * 核销优惠券(使用后标记)。
     * 条件原子更新:仅当券仍为 active 时才核销,并发双花时影响行数为 0 → 抛异常回滚订单。
     */
    public static function apply(Coupon $coupon, int $orderId, ?int $userId = null): void
    {
        $updated = Coupon::whereKey($coupon->id)
            ->where('status', Coupon::STATUS_ACTIVE)
            ->update([
                'status' => Coupon::STATUS_USED,
                'used_at' => now(),
                'used_by' => $userId,
                'order_id' => $orderId,
            ]);

        if ($updated === 0) {
            throw new \RuntimeException(__('messages.coupon.invalid'));
        }
    }

    /**
     * 回滚已核销的优惠券(订单关闭/取消时):仅当券绑定该订单且状态为已使用。
     * 条件更新保证并发安全,未命中(无券/已回滚)静默返回。
     */
    public static function release(int $orderId): void
    {
        Coupon::where('order_id', $orderId)
            ->where('status', Coupon::STATUS_USED)
            ->update([
                'status' => Coupon::STATUS_ACTIVE,
                'used_at' => null,
                'used_by' => null,
                'order_id' => null,
            ]);
    }

    /**
     * 切换启用/禁用。
     */
    public static function toggle(int $couponId): Coupon
    {
        $coupon = Coupon::findOrFail($couponId);
        if ($coupon->status === Coupon::STATUS_USED) {
            throw new \RuntimeException('已使用的优惠券不能切换状态');
        }
        $coupon->update([
            'status' => $coupon->status === Coupon::STATUS_ACTIVE
                ? Coupon::STATUS_DISABLED
                : Coupon::STATUS_ACTIVE,
        ]);

        return $coupon->fresh();
    }
}
