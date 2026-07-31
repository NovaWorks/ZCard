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
        Currency::create([
            'code' => 'CNY', 'name' => '人民币', 'symbol' => '¥',
            'symbol_position' => 'before', 'decimal_places' => 2,
            'exchange_rate' => '1', 'is_base' => true, 'is_enabled' => true, 'sort' => 0,
        ]);
        Currency::create([
            'code' => 'USD', 'name' => '美元', 'symbol' => '$',
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
}
