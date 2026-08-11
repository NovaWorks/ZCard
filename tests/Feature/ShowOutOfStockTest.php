<?php

namespace Tests\Feature;

use App\Models\Card;
use App\Models\Category;
use App\Models\Currency;
use App\Models\Merchant;
use App\Models\Product;
use App\Models\SupplySource;
use App\Models\User;
use App\Support\CardCipher;
use App\Support\StorefrontConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * 商品展示开关:show_out_of_stock=false 时前台列表/推荐不显示缺货商品。
 */
class ShowOutOfStockTest extends TestCase
{
    use RefreshDatabase;

    private function seedBase(): void
    {
        Currency::firstOrCreate(['code' => 'CNY'], [
            'name' => '人民币', 'symbol' => '¥', 'symbol_position' => 'before',
            'decimal_places' => 2, 'exchange_rate' => '1', 'is_base' => true,
            'is_enabled' => true, 'sort' => 0,
        ]);
        Cache::flush();
    }

    private function makeProduct(string $name, int $stock): Product
    {
        $u = User::factory()->create();
        $m = Merchant::firstOrCreate(['slug' => 'default'], ['user_id' => $u->id, 'name' => '主站', 'status' => 1, 'commission_rate' => 0]);
        $c = Category::create(['merchant_id' => $m->id, 'name' => 'C', 'slug' => 'c'.uniqid(), 'sort' => 0]);
        $p = Product::create([
            'merchant_id' => $m->id, 'category_id' => $c->id, 'name' => $name,
            'slug' => 'p'.uniqid(), 'price' => 1000, 'factory_price' => 600,
            'stock_type' => 'card', 'delivery_mode' => 'status', 'status' => true, 'sort' => 0,
        ]);
        for ($i = 0; $i < $stock; $i++) {
            Card::create(array_merge([
                'product_id' => $p->id,
                'status' => Card::STATUS_UNUSED,
            ], CardCipher::encryptWithHash($name.'-'.$i.uniqid())));
        }

        return $p;
    }

    private function makeUpstreamProduct(string $name, int $stockCache): Product
    {
        $u = User::factory()->create();
        $m = Merchant::firstOrCreate(['slug' => 'default'], ['user_id' => $u->id, 'name' => '主站', 'status' => 1, 'commission_rate' => 0]);
        $c = Category::create(['merchant_id' => $m->id, 'name' => 'C', 'slug' => 'c'.uniqid(), 'sort' => 0]);
        $src = SupplySource::create(['name' => 'S', 'driver' => 'dujiao_next', 'base_url' => 'https://x.com', 'credentials' => [], 'status' => 'active', 'settings' => []]);

        return Product::create([
            'merchant_id' => $m->id, 'category_id' => $c->id, 'name' => $name,
            'slug' => 'p'.uniqid(), 'price' => 1000, 'factory_price' => 600,
            'stock_type' => 'card', 'delivery_mode' => 'status', 'status' => true, 'sort' => 0,
            'upstream_source_id' => $src->id, 'upstream_product_code' => 'UP'.uniqid(),
            'stock_cache' => $stockCache,
        ]);
    }

    public function test_index_hides_out_of_stock_when_switch_off(): void
    {
        $this->seedBase();
        StorefrontConfig::setMany(['show_out_of_stock' => false]);
        $this->makeProduct('有货A', 2);
        $this->makeProduct('缺货B', 0);

        $resp = $this->getJson('/api/products?page=1');
        $resp->assertOk();

        $names = array_column($resp->json('data'), 'name');
        $this->assertContains('有货A', $names);
        $this->assertNotContains('缺货B', $names);
    }

    public function test_index_shows_out_of_stock_when_switch_on(): void
    {
        $this->seedBase();
        StorefrontConfig::setMany(['show_out_of_stock' => true]);
        $this->makeProduct('缺货B', 0);

        $resp = $this->getJson('/api/products?page=1');
        $resp->assertOk();

        $names = array_column($resp->json('data'), 'name');
        $this->assertContains('缺货B', $names);
    }

    public function test_upstream_zero_stock_hidden_when_switch_off(): void
    {
        $this->seedBase();
        StorefrontConfig::setMany(['show_out_of_stock' => false]);
        $this->makeUpstreamProduct('上游缺货', 0);
        $this->makeUpstreamProduct('上游无限', -1);

        $resp = $this->getJson('/api/products?page=1');
        $resp->assertOk();

        $names = array_column($resp->json('data'), 'name');
        $this->assertNotContains('上游缺货', $names);
        $this->assertContains('上游无限', $names); // -1 = 无限,始终显示
    }

    public function test_featured_hides_out_of_stock_when_switch_off(): void
    {
        $this->seedBase();
        StorefrontConfig::setMany(['show_out_of_stock' => false]);
        $p1 = $this->makeProduct('推荐有货', 2);
        $p2 = $this->makeProduct('推荐缺货', 0);
        $p1->update(['is_featured' => true]);
        $p2->update(['is_featured' => true]);

        $resp = $this->getJson('/api/products/featured');
        $resp->assertOk();

        $names = array_column($resp->json(), 'name');
        $this->assertContains('推荐有货', $names);
        $this->assertNotContains('推荐缺货', $names);
    }
}
