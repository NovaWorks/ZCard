<?php

namespace Tests\Feature;

use App\Models\Card;
use App\Models\Category;
use App\Models\Currency;
use App\Models\Merchant;
use App\Models\Product;
use App\Models\SubsiteDomain;
use App\Models\SubsiteLedgerEntry;
use App\Models\SubsiteProductSetting;
use App\Models\User;
use App\Support\CardCipher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class SubsiteSettlementTest extends TestCase
{
    use RefreshDatabase;

    public function test_payment_posts_profit_to_ledger(): void
    {
        Currency::create(['code' => 'CNY', 'name' => '人民币', 'symbol' => '¥', 'symbol_position' => 'before', 'decimal_places' => 2, 'exchange_rate' => '1', 'is_base' => true, 'is_enabled' => true, 'sort' => 0]);
        config(['zcard.features.sub_site' => true]);
        config(['zcard.features.distribution' => false]);
        Cache::flush();

        $mainUser = User::factory()->create();
        $mainMerchant = Merchant::firstOrCreate(['slug' => 'default'], ['user_id' => $mainUser->id, 'name' => '主站', 'status' => 1, 'commission_rate' => 0]);
        $cat = Category::create(['merchant_id' => $mainMerchant->id, 'name' => 'C', 'slug' => 'c', 'sort' => 0]);
        $product = Product::create(['merchant_id' => $mainMerchant->id, 'category_id' => $cat->id, 'name' => 'P', 'slug' => 'p', 'price' => 10000, 'factory_price' => 6000, 'stock_type' => 'card', 'delivery_mode' => 'status', 'status' => true, 'sort' => 0]);
        // encryptWithHash returns ['content' => ..., 'content_hash' => ...] (associative, not indexed)
        for ($i = 0; $i < 3; $i++) {
            Card::create(array_merge([
                'product_id' => $product->id,
                'status' => Card::STATUS_UNUSED,
            ], CardCipher::encryptWithHash('card-' . $i . uniqid())));
        }
        $owner = User::factory()->create();
        $subsite = Merchant::create(['user_id' => $owner->id, 'name' => 'Sub', 'slug' => 'sub', 'status' => 1, 'commission_rate' => 0, 'settings' => ['is_subsite' => true]]);
        SubsiteDomain::create(['merchant_id' => $subsite->id, 'domain' => 'settle.test', 'type' => 'custom', 'verification_status' => 'verified', 'status' => 'active', 'is_primary' => true, 'verified_at' => now()]);
        SubsiteProductSetting::create(['merchant_id' => $subsite->id, 'product_id' => $product->id, 'sku_id' => 0, 'is_listed' => true, 'pricing_mode' => 'markup_percent', 'markup_percent' => 10]);

        $buyer = User::factory()->create();
        request()->attributes->set('subsite', $subsite);
        $order = app(\App\Support\OrderService::class)->createOrder($product->id, null, 1, ['contact' => 'b@x.com', 'user_id' => $buyer->id]);
        app(\App\Support\OrderService::class)->markPaid($order->order_no);

        $entry = SubsiteLedgerEntry::where('order_id', $order->id)->where('type', 'order_profit')->first();
        $this->assertNotNull($entry, '应有 order_profit ledger 条目');
        $this->assertSame(1000, (int) $entry->amount); // 利润 1000 分
        $this->assertSame('pending', $entry->status); // 冻结期
    }
}
