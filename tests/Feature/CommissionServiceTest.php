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
use Tests\TestCase;

class CommissionServiceTest extends TestCase
{
    use RefreshDatabase;

    private function seedCurrencies(): void
    {
        Currency::firstOrCreate(['code' => 'CNY'], [
            'name' => '人民币', 'symbol' => '¥',
            'symbol_position' => 'before', 'decimal_places' => 2,
            'exchange_rate' => '1', 'is_base' => true, 'is_enabled' => true, 'sort' => 0,
        ]);
    }

    private function makeProduct(int $price, int $cost): Product
    {
        $u = User::factory()->create();
        $m = Merchant::create([
            'user_id' => $u->id, 'name' => 'M', 'slug' => 'm'.uniqid(),
            'status' => 1, 'commission_rate' => 0,
        ]);
        $c = Category::create([
            'merchant_id' => $m->id, 'name' => 'C', 'slug' => 'c'.uniqid(), 'sort' => 0,
        ]);
        $p = Product::create([
            'merchant_id' => $m->id, 'category_id' => $c->id,
            'name' => 'P', 'slug' => 'p'.uniqid(),
            'price' => $price, 'factory_price' => $cost,
            'stock_type' => 'card', 'delivery_mode' => 'status',
            'status' => true, 'sort' => 0,
        ]);
        // 库存卡密(DeliveryService 在同事件中解密 → 必须真加密)
        for ($i = 0; $i < 5; $i++) {
            $enc = CardCipher::encryptWithHash('card-'.$i.'-'.uniqid());
            Card::create(array_merge([
                'product_id' => $p->id,
                'status' => Card::STATUS_UNUSED,
            ], $enc));
        }

        return $p;
    }

    private function enableDistribution(): void
    {
        config(['zcard.features.distribution' => true]);
        StorefrontConfig::setMany([
            'distribution_enabled' => true,
            'distribution_rate_l1' => 10,
            'distribution_rate_l2' => 5,
            'distribution_rate_l3' => 2,
        ]);
    }

    public function test_three_tier_commission_on_paid_order(): void
    {
        $this->seedCurrencies();
        $this->enableDistribution();
        // 链: l3 <- l2 <- l1 <- buyer
        $l3 = User::factory()->create(['username' => 'l3', 'balance' => 0]);
        $l2 = User::factory()->create(['username' => 'l2', 'pid' => $l3->id, 'balance' => 0]);
        $l1 = User::factory()->create(['username' => 'l1', 'pid' => $l2->id, 'balance' => 0]);
        $buyer = User::factory()->create(['username' => 'buyer', 'pid' => $l1->id, 'balance' => 0]);

        // 售价 10000 分(100元),成本 6000 分(60元),毛利 4000 分(40元)
        $product = $this->makeProduct(10000, 6000);

        $order = app(OrderService::class)->createOrder(
            $product->id, null, 1,
            ['contact' => 'b@x.com', 'user_id' => $buyer->id]
        );
        app(OrderService::class)->markPaid($order->order_no);

        // L1: 4000 × 10% = 400; L2: 4000 × 5% = 200; L3: 4000 × 2% = 80
        $this->assertDatabaseHas('commissions', ['order_id' => $order->id, 'tier' => 1, 'referrer_id' => $l1->id, 'amount' => 400]);
        $this->assertDatabaseHas('commissions', ['order_id' => $order->id, 'tier' => 2, 'referrer_id' => $l2->id, 'amount' => 200]);
        $this->assertDatabaseHas('commissions', ['order_id' => $order->id, 'tier' => 3, 'referrer_id' => $l3->id, 'amount' => 80]);
        $this->assertSame(400, (int) $l1->fresh()->balance);
        $this->assertSame(200, (int) $l2->fresh()->balance);
        $this->assertSame(80, (int) $l3->fresh()->balance);
    }

    public function test_guest_order_no_commission(): void
    {
        $this->seedCurrencies();
        $this->enableDistribution();
        $l1 = User::factory()->create(['balance' => 0]);
        $buyer = User::factory()->create(['pid' => $l1->id, 'balance' => 0]); // logged in but...
        $product = $this->makeProduct(10000, 6000);
        // user_id 不传(模拟游客)
        $order = app(OrderService::class)->createOrder($product->id, null, 1, ['contact' => 'g@x.com']);
        app(OrderService::class)->markPaid($order->order_no);
        $this->assertEquals(0, Commission::where('order_id', $order->id)->count());
    }

    public function test_disabled_distribution_no_commission(): void
    {
        $this->seedCurrencies();
        config(['zcard.features.distribution' => true]);
        StorefrontConfig::setMany(['distribution_enabled' => false]);
        $l1 = User::factory()->create(['balance' => 0]);
        $buyer = User::factory()->create(['pid' => $l1->id, 'balance' => 0]);
        $product = $this->makeProduct(10000, 6000);
        $order = app(OrderService::class)->createOrder($product->id, null, 1, ['contact' => 'b@x.com', 'user_id' => $buyer->id]);
        app(OrderService::class)->markPaid($order->order_no);
        $this->assertEquals(0, Commission::count());
    }

    public function test_feature_flag_off_no_commission(): void
    {
        $this->seedCurrencies();
        // config zcard.features.distribution 默认 false(env),StorefrontConfig 即便 enabled 也不发
        StorefrontConfig::setMany(['distribution_enabled' => true]);
        $l1 = User::factory()->create(['balance' => 0]);
        $buyer = User::factory()->create(['pid' => $l1->id, 'balance' => 0]);
        $product = $this->makeProduct(10000, 6000);
        $order = app(OrderService::class)->createOrder($product->id, null, 1, ['contact' => 'b@x.com', 'user_id' => $buyer->id]);
        app(OrderService::class)->markPaid($order->order_no);
        $this->assertEquals(0, Commission::count());
    }

    public function test_idempotent_no_duplicate_on_repeat(): void
    {
        $this->seedCurrencies();
        $this->enableDistribution();
        $l1 = User::factory()->create(['balance' => 0]);
        $buyer = User::factory()->create(['pid' => $l1->id, 'balance' => 0]);
        $product = $this->makeProduct(10000, 6000);
        $order = app(OrderService::class)->createOrder($product->id, null, 1, ['contact' => 'b@x.com', 'user_id' => $buyer->id]);
        app(OrderService::class)->markPaid($order->order_no);
        // markPaid 第二次会抛"订单状态异常"(已是 paid),不会重复。验证 commissions 仍只有 1 行(L1)
        try {
            app(OrderService::class)->markPaid($order->order_no);
        } catch (\Throwable $e) {
        }
        $this->assertEquals(1, Commission::where('order_id', $order->id)->count());
    }
}
