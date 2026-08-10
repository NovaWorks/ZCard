<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Merchant;
use App\Models\Product;
use App\Models\SupplySource;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * 商品管理按货源商筛选(issue #3:供货商跑路需一键下架其商品)。
 */
class ProductSupplierFilterTest extends TestCase
{
    use RefreshDatabase;

    private function adminHeaders(): array
    {
        foreach (['super_admin', 'merchant', 'user'] as $r) {
            Role::firstOrCreate(['name' => $r]);
        }
        $user = User::factory()->create();
        $user->assignRole('super_admin');

        return ['Authorization' => 'Bearer '.$user->createToken('test')->plainTextToken];
    }

    private function makeProduct(int $sourceId): Product
    {
        $u = User::factory()->create();
        $m = Merchant::firstOrCreate(['slug' => 'default'], ['user_id' => $u->id, 'name' => '主站', 'status' => 1, 'commission_rate' => 0]);
        $c = Category::create(['merchant_id' => $m->id, 'name' => 'C', 'slug' => 'c'.uniqid(), 'sort' => 0]);

        return Product::create([
            'merchant_id' => $m->id, 'category_id' => $c->id, 'name' => '商品'.uniqid(),
            'slug' => 'p'.uniqid(), 'price' => 1000, 'factory_price' => 600,
            'stock_type' => 'card', 'delivery_mode' => 'status', 'status' => 1,
            'upstream_source_id' => $sourceId, 'upstream_product_code' => 'UP'.uniqid(),
            'sort' => 0,
        ]);
    }

    public function test_index_filters_by_upstream_source_id(): void
    {
        $sourceA = SupplySource::create(['name' => '货源A', 'driver' => 'dujiao_next', 'base_url' => 'https://a.com', 'credentials' => [], 'status' => 'active', 'settings' => []]);
        $sourceB = SupplySource::create(['name' => '货源B', 'driver' => 'dujiao_next', 'base_url' => 'https://b.com', 'credentials' => [], 'status' => 'active', 'settings' => []]);
        $this->makeProduct($sourceA->id);
        $this->makeProduct($sourceB->id);
        $this->makeProduct($sourceB->id);

        // 只看货源A
        $resp = $this->withHeaders($this->adminHeaders())
            ->getJson('/api/admin/products?upstream_source_id='.$sourceA->id);
        $resp->assertOk();
        $this->assertSame(1, (int) $resp->json('total'));

        // 只看货源B
        $resp = $this->withHeaders($this->adminHeaders())
            ->getJson('/api/admin/products?upstream_source_id='.$sourceB->id);
        $resp->assertOk();
        $this->assertSame(2, (int) $resp->json('total'));

        // 不带筛选:全部
        $resp = $this->withHeaders($this->adminHeaders())
            ->getJson('/api/admin/products');
        $resp->assertOk();
        $this->assertSame(3, (int) $resp->json('total'));
    }

    public function test_batch_deactivate_supplier_products(): void
    {
        $sourceA = SupplySource::create(['name' => '跑路货源', 'driver' => 'dujiao_next', 'base_url' => 'https://a.com', 'credentials' => [], 'status' => 'active', 'settings' => []]);
        $p1 = $this->makeProduct($sourceA->id);
        $p2 = $this->makeProduct($sourceA->id);

        // 按货源筛选后全选下架
        $resp = $this->withHeaders($this->adminHeaders())
            ->postJson('/api/admin/products/batch', [
                'ids' => [$p1->id, $p2->id],
                'action' => 'deactivate',
            ]);
        $resp->assertOk();
        $this->assertSame(0, (int) $p1->fresh()->status);
        $this->assertSame(0, (int) $p2->fresh()->status);
    }
}
