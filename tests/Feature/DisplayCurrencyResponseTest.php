<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Currency;
use App\Models\Merchant;
use App\Models\Product;
use App\Models\User;
use App\Support\CurrencyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * 商品/订单 API 响应注入 display 字段(Task 6)。
 * 后向兼容:保留 price/amount 旧字段,新增 *_base/*_display/display_currency/exchange_rate。
 */
class DisplayCurrencyResponseTest extends TestCase
{
    use RefreshDatabase;

    /** 构造一个有效商户(merchants.user_id 外键约束)。 */
    private function makeMerchant(): Merchant
    {
        $user = User::create([
            'username' => 'merchant_user',
            'email' => 'merchant@example.com',
            'password' => 'secret',
        ]);

        return Merchant::create([
            'user_id' => $user->id,
            'name' => 'Test Merchant',
            'slug' => 'test-merchant',
        ]);
    }

    /**
     * RefreshDatabase 只迁移不 seed,故手动写入基础货币 CNY + 报价货币 USD。
     * 复刻自 CurrencySeeder(汇率 0.14)。
     */
    private function seedCurrencies(bool $enableUsd = false): void
    {
        Currency::firstOrCreate(['code' => 'CNY'], [
            'name' => '人民币', 'symbol' => '¥',
            'symbol_position' => 'before', 'decimal_places' => 2,
            'exchange_rate' => '1', 'is_base' => true, 'is_enabled' => true, 'sort' => 0,
        ]);
        Currency::updateOrCreate(['code' => 'USD'], [
            'name' => '美元', 'symbol' => '$',
            'symbol_position' => 'before', 'decimal_places' => 2,
            'exchange_rate' => '0.14000000', 'is_base' => false,
            'is_enabled' => $enableUsd, 'sort' => 1,
        ]);
        Cache::forget(CurrencyService::CACHE_KEY);
    }

    public function test_product_list_has_display_currency_fields(): void
    {
        $merchant = $this->makeMerchant();
        $cat = Category::create([
            'merchant_id' => $merchant->id,
            'name' => 'Cat',
            'slug' => 'cat',
            'sort' => 0,
        ]);
        $p = Product::create([
            'merchant_id' => $merchant->id,
            'category_id' => $cat->id,
            'name' => 'Test Product',
            'slug' => 'test-product',
            'price' => 1250,
            'stock_type' => 'card',
            'delivery_mode' => 'status',
            'status' => true,
            'sort' => 0,
        ]);

        // 启用 USD(默认禁用)+ 清缓存,使中间件接受 X-Currency: USD
        $this->seedCurrencies(enableUsd: true);

        $resp = $this->withHeaders(['X-Currency' => 'USD'])->getJson('/api/products');

        $resp->assertOk();
        $item = collect($resp->json('data'))->firstWhere('id', $p->id);
        $this->assertNotNull($item);
        $this->assertSame(1250, $item['price']);               // 旧字段后向兼容
        $this->assertSame(1250, $item['price_base']);          // 基础货币分不变
        $this->assertSame('USD', $item['display_currency']);
        $this->assertSame(175, $item['price_display']);        // 12.50 × 0.14 = 1.75 → 175
        $this->assertSame('0.14000000', $item['exchange_rate']);
    }

    public function test_product_list_falls_back_to_base_currency_without_header(): void
    {
        $merchant = $this->makeMerchant();
        $cat = Category::create([
            'merchant_id' => $merchant->id,
            'name' => 'Cat',
            'slug' => 'cat',
            'sort' => 0,
        ]);
        $p = Product::create([
            'merchant_id' => $merchant->id,
            'category_id' => $cat->id,
            'name' => 'Test Product',
            'slug' => 'test-product-2',
            'price' => 1250,
            'stock_type' => 'card',
            'delivery_mode' => 'status',
            'status' => true,
            'sort' => 0,
        ]);

        // 无 X-Currency 头 → 中间件回退到基础货币 CNY
        $this->seedCurrencies(enableUsd: false);

        $resp = $this->getJson('/api/products');

        $resp->assertOk();
        $item = collect($resp->json('data'))->firstWhere('id', $p->id);
        $this->assertNotNull($item);
        $this->assertSame(1250, $item['price']);               // 后向兼容
        $this->assertSame(1250, $item['price_base']);          // 基础货币分
        $this->assertSame('CNY', $item['display_currency']);   // 回退到基础货币
        $this->assertSame(1250, $item['price_display']);       // 基础货币 → 金额不变
        $this->assertSame('1', $item['exchange_rate']);
    }

    public function test_product_list_rejects_disabled_currency_header(): void
    {
        // 用户后台未启用 USD 但客户端仍带 X-Currency: USD(生产实测场景)
        $merchant = $this->makeMerchant();
        $cat = Category::create([
            'merchant_id' => $merchant->id,
            'name' => 'Cat',
            'slug' => 'cat',
            'sort' => 0,
        ]);
        $p = Product::create([
            'merchant_id' => $merchant->id,
            'category_id' => $cat->id,
            'name' => 'Test Product',
            'slug' => 'test-product-3',
            'price' => 1250,
            'stock_type' => 'card',
            'delivery_mode' => 'status',
            'status' => true,
            'sort' => 0,
        ]);

        // USD 未启用(用户只设了汇率没勾启用)→ 中间件应回退 CNY,防止错误换算
        $this->seedCurrencies(enableUsd: false);

        $resp = $this->withHeaders(['X-Currency' => 'USD'])->getJson('/api/products');

        $resp->assertOk();
        $item = collect($resp->json('data'))->firstWhere('id', $p->id);
        $this->assertNotNull($item);
        $this->assertSame('CNY', $item['display_currency']);   // 未启用 → 回退基础货币
        $this->assertSame(1250, $item['price_display']);       // 金额不错误换算
    }

    public function test_order_snapshot_uses_middleware_currency(): void
    {
        // 下单不传 display_currency 字段(前端实际行为),快照应取中间件解析的 X-Currency
        $merchant = $this->makeMerchant();
        $cat = Category::create([
            'merchant_id' => $merchant->id,
            'name' => 'Cat',
            'slug' => 'cat',
            'sort' => 0,
        ]);
        $p = Product::create([
            'merchant_id' => $merchant->id,
            'category_id' => $cat->id,
            'name' => 'Test Product',
            'slug' => 'test-product-snapshot',
            'price' => 1250,
            'stock_type' => 'card',
            'delivery_mode' => 'status',
            'status' => true,
            'sort' => 0,
        ]);
        \App\Models\Card::create([
            'product_id' => $p->id,
            'content' => 'card-'.uniqid(),
            'content_hash' => hash('sha256', 'card-snapshot'),
            'status' => 'unused',
        ]);

        $this->seedCurrencies(enableUsd: true);
        \Illuminate\Support\Facades\DB::table('settings')->updateOrInsert(
            ['key' => 'trade_captcha', 'group' => 'storefront'],
            ['value' => 'false'],
        );

        $resp = $this->withHeaders(['X-Currency' => 'USD'])->postJson('/api/orders', [
            'product_id' => $p->id,
            'qty' => 1,
            'contact' => 'buyer@test.com',
        ]);
        $resp->assertCreated();
        $orderNo = $resp->json('order_no');
        $this->assertNotEmpty($orderNo);

        $order = \App\Models\Order::where('order_no', $orderNo)->first();
        $this->assertNotNull($order);
        // 金额始终为基础货币分(记账真相源)
        $this->assertSame(1250, (int) $order->amount);
        // 快照 currency = 中间件解析的 USD,而非请求体缺失 → 基础货币
        $this->assertSame('USD', $order->display_currency);
        $this->assertSame(175, (int) $order->amount_display);
        $this->assertSame('0.14000000', $order->exchange_rate);
    }
}
