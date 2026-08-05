<?php

namespace Tests\Feature;

use App\Models\Card;
use App\Models\Category;
use App\Models\Commission;
use App\Models\Currency;
use App\Models\Merchant;
use App\Models\Product;
use App\Models\User;
use App\Support\CardCipher;
use App\Support\OrderService;
use App\Support\StorefrontConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * 三级分销全流程端到端测试:
 * 推广链接注册 → 上下级链建立 → 下级下单付款 → 三级佣金入账
 */
class DistributionEndToEndTest extends TestCase
{
    use RefreshDatabase;

    private function setupContext(): array
    {
        Currency::firstOrCreate(['code' => 'CNY'], ['name' => '人民币', 'symbol' => '¥', 'symbol_position' => 'before', 'decimal_places' => 2, 'exchange_rate' => '1', 'is_base' => true, 'is_enabled' => true, 'sort' => 0]);
        config(['zcard.features.distribution' => true]);
        config(['zcard.features.sub_site' => false]);
        StorefrontConfig::setMany([
            'distribution_enabled' => true,
            'distribution_rate_l1' => 10,
            'distribution_rate_l2' => 5,
            'distribution_rate_l3' => 2,
            'register_open' => true,
            'username_min_length' => 3,
        ]);

        // 角色(RefreshDatabase 清空权限表)
        foreach (['super_admin', 'merchant', 'user'] as $r) {
            Role::firstOrCreate(['name' => $r]);
        }

        $mainUser = User::factory()->create();
        $m = Merchant::firstOrCreate(['slug' => 'default'], ['user_id' => $mainUser->id, 'name' => '主站', 'status' => 1, 'commission_rate' => 0]);
        $cat = Category::create(['merchant_id' => $m->id, 'name' => 'C', 'slug' => 'cat', 'sort' => 0]);
        $product = Product::create([
            'merchant_id' => $m->id, 'category_id' => $cat->id, 'name' => '测试卡', 'slug' => 'test-card',
            'price' => 10000, 'factory_price' => 6000, 'stock_type' => 'card', 'delivery_mode' => 'status', 'status' => true, 'sort' => 0,
        ]);
        for ($i = 0; $i < 5; $i++) {
            Card::create(array_merge(
                ['product_id' => $product->id, 'dedup_hash' => null, 'status' => Card::STATUS_UNUSED],
                CardCipher::encryptWithHash('key-'.$i.uniqid())
            ));
        }

        return [$product, $m];
    }

    public function test_full_distribution_flow_with_referral_registration(): void
    {
        [$product] = $this->setupContext();

        // Step 1: 推广人注册(普通用户)
        $l3 = User::factory()->create(['username' => 'grandpa', 'balance' => 0]);
        $l2 = User::factory()->create(['username' => 'parent', 'pid' => $l3->id, 'balance' => 0]);
        $l1 = User::factory()->create(['username' => 'referrer', 'pid' => $l2->id, 'balance' => 0]);

        // Step 2: 通过推广链接注册新用户(模拟 API 注册带 referrer)
        $resp = $this->postJson('/api/auth/register', [
            'username' => 'newbie',
            'email' => 'newbie@test.com',
            'password' => 'password123',
            'referrer' => 'referrer', // 推广人用户名
        ]);
        $resp->assertCreated();
        $buyer = User::where('username', 'newbie')->first();
        $this->assertSame($l1->id, $buyer->pid, '新用户 pid 应绑定到推广人 referrer');

        // Step 3: 下级下单(售价 100,成本 60,毛利 40)
        $order = app(OrderService::class)->createOrder(
            $product->id, null, 1,
            ['contact' => 'newbie@test.com', 'user_id' => $buyer->id]
        );
        $this->assertSame(10000, (int) $order->amount);

        // Step 4: 付款 → 触发三级佣金
        app(OrderService::class)->markPaid($order->order_no);

        // 毛利 = 10000 - 6000 = 4000 分
        // L1(referrer): 4000 × 10% = 400
        // L2(parent):   4000 × 5%  = 200
        // L3(grandpa):  4000 × 2%  = 80
        $this->assertDatabaseHas('commissions', ['order_id' => $order->id, 'tier' => 1, 'referrer_id' => $l1->id, 'amount' => 400]);
        $this->assertDatabaseHas('commissions', ['order_id' => $order->id, 'tier' => 2, 'referrer_id' => $l2->id, 'amount' => 200]);
        $this->assertDatabaseHas('commissions', ['order_id' => $order->id, 'tier' => 3, 'referrer_id' => $l3->id, 'amount' => 80]);

        // Step 5: 余额正确入账
        $this->assertSame(400, (int) $l1->fresh()->balance, 'L1 余额应增 400 分');
        $this->assertSame(200, (int) $l2->fresh()->balance, 'L2 余额应增 200 分');
        $this->assertSame(80, (int) $l3->fresh()->balance, 'L3 余额应增 80 分');
    }

    public function test_self_referral_rejected_at_register(): void
    {
        $this->setupContext();
        $resp = $this->postJson('/api/auth/register', [
            'username' => 'ego',
            'email' => 'ego@test.com',
            'password' => 'password123',
            'referrer' => 'ego', // 自推荐
        ]);
        $resp->assertCreated();
        $this->assertSame(0, User::where('username', 'ego')->first()->pid, '自推荐 pid 应为 0');
    }

    public function test_distribution_disabled_no_commission(): void
    {
        [$product] = $this->setupContext();
        StorefrontConfig::setMany(['distribution_enabled' => false]);

        $l1 = User::factory()->create(['balance' => 0]);
        $buyer = User::factory()->create(['pid' => $l1->id]);

        $order = app(OrderService::class)->createOrder($product->id, null, 1, ['contact' => 'b@x.com', 'user_id' => $buyer->id]);
        app(OrderService::class)->markPaid($order->order_no);

        $this->assertEquals(0, Commission::where('order_id', $order->id)->count(), '分销关闭时不应有佣金');
        $this->assertSame(0, (int) $l1->fresh()->balance, '分销关闭时推广人余额不变');
    }
}
