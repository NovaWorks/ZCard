<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Currency;
use App\Models\Merchant;
use App\Models\Order;
use App\Models\Product;
use App\Models\SupplySource;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * 订单详情增强:上游货源信息/财务信息 + 上游订单手动发货 + 手动重新拿货。
 */
class OrderUpstreamDetailTest extends TestCase
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

    private function makeUpstreamOrder(): Order
    {
        $user = User::factory()->create();
        Merchant::query()->firstOrCreate(
            ['id' => 1],
            ['name' => '主站', 'slug' => 'main-'.uniqid(), 'user_id' => $user->id, 'settings' => []],
        );
        Currency::firstOrCreate(['code' => 'CNY'], ['name' => '人民币', 'symbol' => '¥', 'symbol_position' => 'before', 'decimal_places' => 2, 'exchange_rate' => '1', 'is_base' => true, 'is_enabled' => true, 'sort' => 0]);
        $source = SupplySource::create([
            'name' => '上游货源', 'driver' => 'acg_faka', 'base_url' => 'https://up.example.com',
            'credentials' => [], 'status' => 'active', 'settings' => [],
        ]);
        $category = Category::create(['merchant_id' => 1, 'name' => 'C', 'slug' => 'c-'.uniqid(), 'sort' => 0]);
        $product = Product::create([
            'merchant_id' => 1, 'category_id' => $category->id, 'name' => 'P', 'slug' => 'p-'.uniqid(),
            'price' => 1000, 'factory_price' => 600, 'stock_type' => 'card', 'status' => true, 'sort' => 0,
            'upstream_source_id' => $source->id, 'upstream_product_code' => 'UP123',
            'upstream_product_url' => 'https://up.example.com/?cid=5&mid=101',
        ]);

        return Order::create([
            'order_no' => 'ORD'.uniqid(), 'merchant_id' => 1, 'user_id' => null,
            'product_id' => $product->id, 'quantity' => 2, 'amount' => 2000, 'cost' => 1200,
            'status' => 'paid', 'paid_at' => now(), 'delivery_status' => 'pending',
            'fulfillment_type_snapshot' => Product::FULFILLMENT_UPSTREAM,
        ]);
    }

    public function test_product_list_includes_upstream_price_and_link(): void
    {
        $order = $this->makeUpstreamOrder();
        $product = Product::find($order->product_id);

        $resp = $this->withHeaders($this->adminHeaders())
            ->getJson('/api/admin/products?pageSize=15');
        $resp->assertOk();
        $row = collect($resp->json('data'))->firstWhere('id', $product->id);
        $this->assertNotNull($row);
        // 模拟同步写入上游售价快照
        $product->update(['upstream_price' => 750]);
        $resp = $this->withHeaders($this->adminHeaders())
            ->getJson('/api/admin/products?pageSize=15');
        $row = collect($resp->json('data'))->firstWhere('id', $product->id);
        $this->assertSame(750, (int) $row['upstream_price']);
        $this->assertSame('https://up.example.com/?cid=5&mid=101', $row['upstream_product_url']);
    }

    public function test_order_detail_includes_finance_and_upstream_info(): void
    {
        $order = $this->makeUpstreamOrder();

        $resp = $this->withHeaders($this->adminHeaders())->getJson("/api/admin/orders/{$order->id}");
        $resp->assertOk();

        $data = $resp->json();
        $this->assertSame(2000, $data['unit_price']);
        $this->assertSame(1200, $data['unit_cost']);
        $this->assertSame(800, $data['profit']); // 2000 - 1200
        $this->assertSame(40.0, (float) $data['profit_rate']);
        $this->assertSame('上游货源', $data['upstream_source_name']);
        $this->assertSame('https://up.example.com/?cid=5&mid=101', $data['upstream_product_url']);
    }

    public function test_upstream_order_can_be_fulfilled_manually(): void
    {
        $order = $this->makeUpstreamOrder();

        $resp = $this->withHeaders($this->adminHeaders())
            ->postJson("/api/admin/orders/{$order->id}/fulfill", ['content' => 'MANUAL-CODE-1']);
        $resp->assertOk();
        $this->assertSame('delivered', $resp->json('delivery_status'));
    }

    public function test_refetch_upstream_on_local_product_rejected(): void
    {
        $order = $this->makeUpstreamOrder();
        // 商品不再是上游货源商品
        Product::where('id', $order->product_id)->update(['upstream_source_id' => null]);

        $resp = $this->withHeaders($this->adminHeaders())
            ->postJson("/api/admin/orders/{$order->id}/refetch-upstream");
        $resp->assertStatus(422);
    }

    public function test_refetch_upstream_delivered_order_rejected(): void
    {
        $order = $this->makeUpstreamOrder();
        $order->update(['delivery_status' => 'delivered']);

        $resp = $this->withHeaders($this->adminHeaders())
            ->postJson("/api/admin/orders/{$order->id}/refetch-upstream");
        $resp->assertStatus(409);
    }
}
