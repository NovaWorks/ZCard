<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\ProductController;
use App\Models\Category;
use App\Models\Currency;
use App\Models\Merchant;
use App\Models\Product;
use App\Models\SubsiteProductSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * 分站商品可见性与定价:ProductController 改造后的行为验证。
 *
 * 注意:Laravel test client 用 Request::create($uri) 发起请求,Host 取自 URI(localhost),
 * withHeader('Host', ...) 会被 Symfony 内部覆盖 → 分站中间件无法在测试中通过 Host 解析。
 * 因此:
 *  - 主站行为(/api/products 无子域)用真实 HTTP 断言;
 *  - 分站可见性过滤 + 加价逻辑,用「手动注入 request attribute + 直接调 ProductController」
 *    覆盖,等效于中间件解析到分站后的真实执行路径(spec §4)。
 */
class SubsiteProductVisibilityTest extends TestCase
{
    use RefreshDatabase, WithFaker;

    private function seedCurrency(): void
    {
        Currency::firstOrCreate(['code' => 'CNY'], ['name' => '人民币', 'symbol' => '¥', 'symbol_position' => 'before', 'decimal_places' => 2, 'exchange_rate' => '1', 'is_base' => true, 'is_enabled' => true, 'sort' => 0]);
    }

    private function makeMainProduct(int $price): Product
    {
        $u = User::factory()->create();
        $m = Merchant::firstOrCreate(['slug' => 'default'], ['user_id' => $u->id, 'name' => '默认商户', 'status' => 1, 'commission_rate' => 0]);
        $c = Category::create(['merchant_id' => $m->id, 'name' => 'C', 'slug' => 'c'.uniqid(), 'sort' => 0]);

        return Product::create([
            'merchant_id' => $m->id, 'category_id' => $c->id, 'name' => 'P', 'slug' => 'p'.uniqid(),
            'price' => $price, 'stock_type' => 'card', 'delivery_mode' => 'status', 'status' => true, 'sort' => 0,
        ]);
    }

    private function makeSubsite(Merchant $main): Merchant
    {
        $owner = User::factory()->create();

        return Merchant::create([
            'user_id' => $owner->id, 'name' => 'Sub', 'slug' => 'sub'.uniqid(),
            'status' => 1, 'commission_rate' => 0,
            'settings' => ['is_subsite' => true, 'default_markup_percent' => 0, 'max_markup_percent' => 50],
        ]);
    }

    public function test_main_site_shows_all_products_unmarked(): void
    {
        $this->seedCurrency();
        $product = $this->makeMainProduct(10000);
        $resp = $this->getJson('/api/products');
        $resp->assertOk();
        $items = collect($resp->json('data'));
        $this->assertTrue($items->where('id', $product->id)->isNotEmpty(), '主站应显示所有商品');
    }

    public function test_main_site_returns_base_price(): void
    {
        $this->seedCurrency();
        // 主站(无子域)→ subsite=null → 原价返回,验证 transform() 基础分支未被加价。
        $product = $this->makeMainProduct(10000);
        $resp = $this->getJson('/api/products');
        $resp->assertOk();
        $item = collect($resp->json('data'))->firstWhere('id', $product->id);
        $this->assertNotNull($item);
        $this->assertSame(10000, $item['price_base']);
    }

    /**
     * 分站可见性过滤:模拟中间件解析到分站后,直接调 ProductController->index(),
     * 断言 is_listed=false 的商品被 whereNotIn 排除。
     */
    public function test_subsite_hides_unlisted_product(): void
    {
        $this->seedCurrency();
        $main = Merchant::firstOrCreate(['slug' => 'default'], ['user_id' => User::factory()->create()->id, 'name' => '默认商户', 'status' => 1, 'commission_rate' => 0]);
        $listed = $this->makeMainProduct(10000);
        $unlisted = $this->makeMainProduct(20000);
        $sub = $this->makeSubsite($main);
        SubsiteProductSetting::create(['merchant_id' => $sub->id, 'product_id' => $unlisted->id, 'sku_id' => 0, 'is_listed' => false, 'pricing_mode' => 'inherit']);

        $request = Request::create('/api/products', 'GET');
        $request->attributes->set('subsite', $sub);
        // 控制器用 request()->attributes,需把 request 注入容器当前实例
        app()->instance('request', $request);

        $controller = app(ProductController::class);
        $resp = $controller->index($request);
        $items = collect(json_decode($resp->getContent(), true)['data'] ?? []);

        $this->assertTrue($items->where('id', $unlisted->id)->isEmpty(), '分站应隐藏未上架商品');
        $this->assertTrue($items->where('id', $listed->id)->isNotEmpty(), '分站应显示未设规则的商品(继承上架)');
    }

    /**
     * 分站加价:模拟中间件解析到分站后,断言 transform() 走 SubsitePricingService。
     */
    public function test_subsite_applies_markup_to_price_base(): void
    {
        $this->seedCurrency();
        $main = Merchant::firstOrCreate(['slug' => 'default'], ['user_id' => User::factory()->create()->id, 'name' => '默认商户', 'status' => 1, 'commission_rate' => 0]);
        $product = $this->makeMainProduct(10000);
        $sub = $this->makeSubsite($main);
        SubsiteProductSetting::create(['merchant_id' => $sub->id, 'product_id' => $product->id, 'sku_id' => 0, 'is_listed' => true, 'pricing_mode' => 'markup_percent', 'markup_percent' => 10]);

        $request = Request::create('/api/products', 'GET');
        $request->attributes->set('subsite', $sub);
        app()->instance('request', $request);

        $controller = app(ProductController::class);
        $resp = $controller->index($request);
        $item = collect(json_decode($resp->getContent(), true)['data'] ?? [])->firstWhere('id', $product->id);

        $this->assertNotNull($item);
        $this->assertSame(11000, $item['price_base'], '分站应按 10% 加价(10000→11000)');
        $this->assertSame(11000, $item['price'], 'price 字段同步生效价');
    }
}
