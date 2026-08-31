<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Currency;
use App\Models\Merchant;
use App\Models\Order;
use App\Models\Product;
use App\Models\SupplySource;
use App\Models\User;
use App\Support\CurrencyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * 缺货禁下单:上游商品依据 stock_cache 在付款前拒单,
 * 防止顾客对缺货商品付款后在上游拿货失败(付款成功但发不出货)。
 */
class UpstreamStockOrderGuardTest extends TestCase
{
    use RefreshDatabase;

    private function seedCurrencies(): void
    {
        Currency::firstOrCreate(['code' => 'CNY'], [
            'name' => '人民币', 'symbol' => '¥',
            'symbol_position' => 'before', 'decimal_places' => 2,
            'exchange_rate' => '1', 'is_base' => true, 'is_enabled' => true, 'sort' => 0,
        ]);
        Cache::forget(CurrencyService::CACHE_KEY);
        // 关闭下单验证码(默认开启),本测试只关注库存拦截
        DB::table('settings')->updateOrInsert(
            ['key' => 'trade_captcha', 'group' => 'storefront'],
            ['value' => 'false'],
        );
    }

    private function makeUpstreamProduct(?int $stockCache): Product
    {
        $user = User::create([
            'username' => 'merchant_user_'.uniqid(),
            'email' => 'merchant_'.uniqid().'@example.com',
            'password' => 'secret',
        ]);
        $merchant = Merchant::create([
            'user_id' => $user->id,
            'name' => 'Test Merchant',
            'slug' => 'test-merchant-'.uniqid(),
        ]);
        $source = SupplySource::create([
            'name' => '上游货源',
            'driver' => 'zcard',
            'base_url' => 'https://example.com',
            'credentials' => [],
        ]);
        $cat = Category::create([
            'merchant_id' => $merchant->id,
            'name' => 'Cat',
            'slug' => 'cat-'.uniqid(),
            'sort' => 0,
        ]);

        return Product::create([
            'merchant_id' => $merchant->id,
            'category_id' => $cat->id,
            'name' => '上游商品',
            'slug' => 'upstream-'.uniqid(),
            'price' => 1000,
            'stock_type' => 'card',
            'delivery_mode' => 'status',
            'status' => true,
            'sort' => 0,
            'upstream_source_id' => $source->id,
            'upstream_product_code' => 'UP-'.uniqid(),
            'stock_cache' => $stockCache,
        ]);
    }

    public function test_upstream_product_with_zero_stock_rejects_order(): void
    {
        $this->seedCurrencies();
        $p = $this->makeUpstreamProduct(0);

        $resp = $this->postJson('/api/orders', [
            'product_id' => $p->id,
            'qty' => 1,
            'contact' => 'buyer@example.com',
            'password' => 'secret123',
        ]);

        $resp->assertStatus(422);
        $this->assertStringContainsString('Insufficient stock', (string) $resp->json('message'));
        $this->assertSame(0, Order::where('product_id', $p->id)->count());
    }

    public function test_upstream_product_rejects_qty_exceeding_cached_stock(): void
    {
        $this->seedCurrencies();
        $p = $this->makeUpstreamProduct(2);

        $resp = $this->postJson('/api/orders', [
            'product_id' => $p->id,
            'qty' => 3,
            'contact' => 'buyer@example.com',
            'password' => 'secret123',
        ]);

        $resp->assertStatus(422);
        $this->assertSame(0, Order::where('product_id', $p->id)->count());
    }

    public function test_upstream_product_allows_order_within_cached_stock(): void
    {
        $this->seedCurrencies();
        $p = $this->makeUpstreamProduct(2);

        $resp = $this->postJson('/api/orders', [
            'product_id' => $p->id,
            'qty' => 2,
            'contact' => 'buyer@example.com',
            'password' => 'secret123',
        ]);

        $resp->assertCreated();
        $this->assertSame(1, Order::where('product_id', $p->id)->count());
    }

    public function test_upstream_product_with_unknown_stock_allows_order(): void
    {
        // stock_cache=null 表示上游库存未知:不拦,由付款后的上游拿货兜底
        $this->seedCurrencies();
        $p = $this->makeUpstreamProduct(null);

        $resp = $this->postJson('/api/orders', [
            'product_id' => $p->id,
            'qty' => 5,
            'contact' => 'buyer@example.com',
            'password' => 'secret123',
        ]);

        $resp->assertCreated();
    }

    public function test_upstream_product_with_unlimited_stock_allows_order(): void
    {
        // stock_cache=-1 表示上游不限量
        $this->seedCurrencies();
        $p = $this->makeUpstreamProduct(-1);

        $resp = $this->postJson('/api/orders', [
            'product_id' => $p->id,
            'qty' => 5,
            'contact' => 'buyer@example.com',
            'password' => 'secret123',
        ]);

        $resp->assertCreated();
    }
}
