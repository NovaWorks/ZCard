<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Currency;
use App\Models\Merchant;
use App\Models\Order;
use App\Models\Product;
use App\Models\Review;
use App\Models\User;
use App\Support\StorefrontConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * 前台评价:入口状态(eligibility)与我的订单 reviewed 标记。
 */
class ReviewEligibilityTest extends TestCase
{
    use RefreshDatabase;

    private function seedBase(): void
    {
        Currency::firstOrCreate(['code' => 'CNY'], [
            'name' => '人民币', 'symbol' => '¥', 'symbol_position' => 'before',
            'decimal_places' => 2, 'exchange_rate' => '1', 'is_base' => true,
            'is_enabled' => true, 'sort' => 0,
        ]);
        StorefrontConfig::setMany(['trade_captcha' => false, 'allow_post_review' => true]);
        Cache::flush();
    }

    private function makeProduct(): Product
    {
        $u = User::factory()->create();
        $m = Merchant::firstOrCreate(['slug' => 'default'], ['user_id' => $u->id, 'name' => '主站', 'status' => 1, 'commission_rate' => 0]);
        $c = Category::create(['merchant_id' => $m->id, 'name' => 'C', 'slug' => 'c'.uniqid(), 'sort' => 0]);

        return Product::create([
            'merchant_id' => $m->id, 'category_id' => $c->id, 'name' => 'P',
            'slug' => 'p'.uniqid(), 'price' => 1000, 'factory_price' => 600,
            'stock_type' => 'card', 'delivery_mode' => 'status', 'status' => true, 'sort' => 0,
        ]);
    }

    private function makePaidOrder(User $user, Product $p): Order
    {
        return Order::create([
            'order_no' => 'ORD'.uniqid(), 'user_id' => $user->id, 'product_id' => $p->id,
            'merchant_id' => 1, 'quantity' => 1, 'amount' => 1000, 'status' => 'paid',
            'delivery_status' => 'delivered', 'contact' => 'a@b.c', 'paid_at' => now(),
        ]);
    }

    private function token(User $user): string
    {
        return $user->createToken('test')->plainTextToken;
    }

    public function test_eligibility_returns_can_review_for_paid_unreviewed_order(): void
    {
        $this->seedBase();
        $user = User::factory()->create();
        $p = $this->makeProduct();
        $this->makePaidOrder($user, $p);

        $resp = $this->withHeaders(['Authorization' => 'Bearer '.$this->token($user)])
            ->getJson('/api/reviews/eligibility/'.$p->id);

        $resp->assertOk();
        $resp->assertJson(['allow_post_review' => true, 'can_review' => true, 'reviewed' => false]);
        $this->assertNotNull($resp->json('order_id'));
    }

    public function test_eligibility_false_after_reviewed(): void
    {
        $this->seedBase();
        $user = User::factory()->create();
        $p = $this->makeProduct();
        $order = $this->makePaidOrder($user, $p);
        Review::create([
            'product_id' => $p->id, 'user_id' => $user->id, 'order_id' => $order->id,
            'rating' => 5, 'content' => '好', 'status' => Review::STATUS_APPROVED,
        ]);

        $resp = $this->withHeaders(['Authorization' => 'Bearer '.$this->token($user)])
            ->getJson('/api/reviews/eligibility/'.$p->id);

        $resp->assertJson(['can_review' => false, 'reviewed' => true]);
    }

    public function test_eligibility_false_when_review_disabled(): void
    {
        $this->seedBase();
        StorefrontConfig::setMany(['allow_post_review' => false]);
        $user = User::factory()->create();
        $p = $this->makeProduct();
        $this->makePaidOrder($user, $p);

        $resp = $this->withHeaders(['Authorization' => 'Bearer '.$this->token($user)])
            ->getJson('/api/reviews/eligibility/'.$p->id);

        $resp->assertJson(['allow_post_review' => false, 'can_review' => false]);
    }

    public function test_my_orders_includes_product_id_and_reviewed_flag(): void
    {
        $this->seedBase();
        $user = User::factory()->create();
        $p = $this->makeProduct();
        $order = $this->makePaidOrder($user, $p);

        $resp = $this->withHeaders(['Authorization' => 'Bearer '.$this->token($user)])
            ->getJson('/api/orders/mine');

        $resp->assertOk();
        $resp->assertJsonCount(1);
        $this->assertSame($p->id, $resp->json('0.product_id'));
        $this->assertSame($order->id, $resp->json('0.id'));
        $this->assertFalse($resp->json('0.reviewed'));
    }
}
