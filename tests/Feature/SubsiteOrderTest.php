<?php

namespace Tests\Feature;

use App\Models\Card;
use App\Models\Category;
use App\Support\CardCipher;
use App\Models\Currency;
use App\Models\Merchant;
use App\Models\Product;
use App\Models\SubsiteDomain;
use App\Models\SubsiteOrderSnapshot;
use App\Models\SubsiteProductSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class SubsiteOrderTest extends TestCase
{
    use RefreshDatabase;

    private function setupSubsiteOrderContext(): array
    {
        Currency::create(['code' => 'CNY', 'name' => '人民币', 'symbol' => '¥', 'symbol_position' => 'before', 'decimal_places' => 2, 'exchange_rate' => '1', 'is_base' => true, 'is_enabled' => true, 'sort' => 0]);
        config(['zcard.features.sub_site' => true]);
        config(['zcard.features.distribution' => false]);
        Cache::flush();

        $mainUser = User::factory()->create();
        $mainMerchant = Merchant::firstOrCreate(['slug' => 'default'], ['user_id' => $mainUser->id, 'name' => '主站', 'status' => 1, 'commission_rate' => 0]);
        $cat = Category::create(['merchant_id' => $mainMerchant->id, 'name' => 'C', 'slug' => 'c', 'sort' => 0]);
        $product = Product::create([
            'merchant_id' => $mainMerchant->id, 'category_id' => $cat->id, 'name' => 'P', 'slug' => 'p',
            'price' => 10000, 'factory_price' => 6000, 'stock_type' => 'card', 'delivery_mode' => 'status', 'status' => true, 'sort' => 0,
        ]);
        // encryptWithHash returns ['content' => ..., 'content_hash' => ...] (associative, not indexed)
        for ($i = 0; $i < 5; $i++) {
            Card::create(array_merge([
                'product_id' => $product->id,
                'status' => Card::STATUS_UNUSED,
            ], CardCipher::encryptWithHash('card-' . $i . uniqid())));
        }

        $owner = User::factory()->create();
        $subsite = Merchant::create(['user_id' => $owner->id, 'name' => 'Sub', 'slug' => 'sub', 'status' => 1, 'commission_rate' => 0, 'settings' => ['is_subsite' => true]]);
        SubsiteDomain::create(['merchant_id' => $subsite->id, 'domain' => 'sub.test', 'type' => 'custom', 'verification_status' => 'verified', 'status' => 'active', 'is_primary' => true, 'verified_at' => now()]);
        SubsiteProductSetting::create(['merchant_id' => $subsite->id, 'product_id' => $product->id, 'sku_id' => 0, 'is_listed' => true, 'pricing_mode' => 'markup_percent', 'markup_percent' => 10]);

        return [$product, $subsite, $owner];
    }

    public function test_subsite_order_uses_markup_price(): void
    {
        [$product, $subsite, $owner] = $this->setupSubsiteOrderContext();
        $buyer = User::factory()->create();

        // 模拟分站上下文(直接设 request attribute,因测试 Host 不可靠)
        request()->attributes->set('subsite', $subsite);

        $order = app(\App\Support\OrderService::class)->createOrder(
            $product->id, null, 1,
            ['contact' => 'b@x.com', 'user_id' => $buyer->id]
        );
        $this->assertSame(11000, (int) $order->amount); // 100 × 1.10
        $this->assertSame($subsite->id, $order->subsite_id);
        $this->assertSame(1000, (int) $order->subsite_profit); // 11000 - 10000
        $this->assertDatabaseHas('subsite_order_snapshots', ['order_id' => $order->id, 'profit_amount' => 1000, 'profit_eligible' => true]);
    }

    public function test_self_dealing_blocks_profit(): void
    {
        [$product, $subsite, $owner] = $this->setupSubsiteOrderContext();
        request()->attributes->set('subsite', $subsite);

        $order = app(\App\Support\OrderService::class)->createOrder(
            $product->id, null, 1,
            ['contact' => $owner->email, 'user_id' => $owner->id] // 分站主自己买
        );
        $this->assertSame(11000, (int) $order->amount); // 订单照走
        $this->assertDatabaseHas('subsite_order_snapshots', ['order_id' => $order->id, 'profit_eligible' => false, 'profit_block_reason' => 'self_dealing_owner']);
        $this->assertSame(0, (int) $order->subsite_profit);
    }
}
