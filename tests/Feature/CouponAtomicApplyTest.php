<?php

namespace Tests\Feature;

use App\Models\Coupon;
use App\Models\Merchant;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Support\CouponService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 优惠券核销原子性(安全审计 M4):已被使用的券再次核销必须失败,
 * 防止并发双花(validate 在事务外,apply 必须是条件原子更新)。
 */
class CouponAtomicApplyTest extends TestCase
{
    use RefreshDatabase;

    private function makeOrder(): Order
    {
        $merchant = Merchant::firstOrCreate(
            ['slug' => 'c1'],
            ['user_id' => User::factory()->create()->id, 'name' => 'C', 'status' => 1, 'commission_rate' => 0],
        );
        $product = Product::create([
            'merchant_id' => $merchant->id, 'name' => 'P', 'slug' => 'c-'.bin2hex(random_bytes(4)),
            'price' => 1000, 'factory_price' => 0, 'status' => 1,
        ]);

        return Order::create([
            'order_no' => 'ORD'.time().bin2hex(random_bytes(2)),
            'merchant_id' => $merchant->id,
            'product_id' => $product->id,
            'quantity' => 1,
            'amount' => 1000,
            'cost' => 0,
            'status' => 'pending',
            'delivery_status' => 'pending',
            'fulfillment_type_snapshot' => 'manual',
        ]);
    }

    public function test_coupon_can_only_be_applied_once(): void
    {
        $coupon = Coupon::create([
            'code' => 'ONCE-ONLY',
            'type' => Coupon::TYPE_FIXED,
            'value' => 100,
            'status' => Coupon::STATUS_ACTIVE,
            'min_amount' => 0,
        ]);

        CouponService::apply($coupon, $this->makeOrder()->id, null);

        $this->assertSame(Coupon::STATUS_USED, $coupon->fresh()->status);

        $this->expectException(\RuntimeException::class);
        CouponService::apply($coupon, $this->makeOrder()->id, null);
    }

    public function test_apply_requires_active_status(): void
    {
        $coupon = Coupon::create([
            'code' => 'DISABLED',
            'type' => Coupon::TYPE_FIXED,
            'value' => 100,
            'status' => Coupon::STATUS_DISABLED,
            'min_amount' => 0,
        ]);

        $this->expectException(\RuntimeException::class);
        CouponService::apply($coupon, $this->makeOrder()->id, null);
    }
}
