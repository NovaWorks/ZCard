<?php

namespace Tests\Feature;

use App\Models\Card;
use App\Models\Category;
use App\Models\Currency;
use App\Models\Merchant;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Models\User;
use App\Models\VisitLog;
use App\Support\CardCipher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * 仪表盘数据端点测试:确保 overview/trends/top-products/top-channels 返回正确结构。
 */
class DashboardTest extends TestCase
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

    private function seedData(): void
    {
        Currency::firstOrCreate(['code' => 'CNY'], ['name' => '人民币', 'symbol' => '¥', 'symbol_position' => 'before', 'decimal_places' => 2, 'exchange_rate' => '1', 'is_base' => true, 'is_enabled' => true, 'sort' => 0]);
        $u = User::factory()->create();
        $m = Merchant::firstOrCreate(['slug' => 'default'], ['user_id' => $u->id, 'name' => 'M', 'status' => 1, 'commission_rate' => 0]);
        $c = Category::create(['merchant_id' => $m->id, 'name' => 'C', 'slug' => 'c', 'sort' => 0]);
        $p = Product::create(['merchant_id' => $m->id, 'category_id' => $c->id, 'name' => 'P', 'slug' => 'p', 'price' => 10000, 'factory_price' => 6000, 'stock_type' => 'card', 'delivery_mode' => 'status', 'status' => true, 'sort' => 0]);
        Card::create(array_merge(
            ['product_id' => $p->id, 'dedup_hash' => null, 'status' => Card::STATUS_UNUSED],
            CardCipher::encryptWithHash('card-'.uniqid())
        ));

        // 已付订单
        $order = Order::create([
            'order_no' => 'ORD001', 'merchant_id' => $m->id, 'user_id' => null, 'product_id' => $p->id,
            'quantity' => 1, 'amount' => 10000, 'cost' => 6000, 'status' => 'paid', 'paid_at' => now(),
        ]);
        Payment::create(['order_id' => $order->id, 'channel' => 'alipay', 'amount' => 10000, 'status' => 'success']);
    }

    public function test_overview_returns_online_users(): void
    {
        $this->seedData();
        $resp = $this->withHeaders($this->adminHeaders())->getJson('/api/admin/dashboard/overview?days=7');
        $resp->assertOk()
            ->assertJsonStructure(['online_users']);
    }

    public function test_traffic_returns_pv_uv_series(): void
    {
        // 造 3 条访问日志:今天 2 个 IP(1 个重复)
        $today = now()->toDateString();
        VisitLog::create(['ip' => '1.2.3.4', 'user_agent' => 'Mozilla', 'path' => '/']);
        VisitLog::create(['ip' => '1.2.3.4', 'user_agent' => 'Mozilla', 'path' => '/product/1']);
        VisitLog::create(['ip' => '5.6.7.8', 'user_agent' => 'Mozilla', 'path' => '/']);

        $resp = $this->withHeaders($this->adminHeaders())->getJson('/api/admin/dashboard/traffic?days=7');
        $resp->assertOk();
        $series = $resp->json();
        $todayRow = collect($series)->firstWhere('date', $today);
        $this->assertNotNull($todayRow, 'traffic 应包含今天');
        $this->assertSame(3, (int) $todayRow['pv'], 'PV = 日志条数');
        $this->assertSame(2, (int) $todayRow['uv'], 'UV = distinct IP');
    }

    public function test_trends_include_refund_metrics(): void
    {
        $this->seedData();
        // 补一条退款单
        $order = Order::where('order_no', 'ORD001')->firstOrFail();
        Order::create([
            'order_no' => 'ORD002', 'merchant_id' => $order->merchant_id, 'user_id' => null, 'product_id' => $order->product_id,
            'quantity' => 1, 'amount' => 5000, 'cost' => 3000, 'status' => 'refunded',
        ]);

        $resp = $this->withHeaders($this->adminHeaders())->getJson('/api/admin/dashboard/trends?days=7');
        $resp->assertOk();
        $todayRow = collect($resp->json())->firstWhere('date', now()->toDateString());
        $this->assertNotNull($todayRow);
        $this->assertSame(1, (int) $todayRow['refunded_count'], '退款单数 = 1');
        $this->assertGreaterThan(0, (float) $todayRow['refund_rate'], '退款率 > 0');
        $this->assertArrayHasKey('refund_rate', $todayRow);
    }

    public function test_overview_returns_all_metrics(): void
    {
        $this->seedData();
        $resp = $this->withHeaders($this->adminHeaders())->getJson('/api/admin/dashboard/overview?days=7');
        $resp->assertOk();
        $data = $resp->json();
        $this->assertArrayHasKey('total_orders', $data);
        $this->assertArrayHasKey('paid_orders', $data);
        $this->assertArrayHasKey('paid_amount', $data);
        $this->assertArrayHasKey('profit', $data);
        $this->assertArrayHasKey('profit_margin', $data);
        $this->assertArrayHasKey('payment_rate', $data);
        $this->assertArrayHasKey('total_stock', $data);
        $this->assertSame(1, $data['paid_orders']);
        $this->assertSame(10000, $data['paid_amount']);
        $this->assertSame(4000, $data['profit']); // 10000 - 6000
    }

    public function test_trends_returns_daily_data(): void
    {
        $this->seedData();
        $resp = $this->withHeaders($this->adminHeaders())->getJson('/api/admin/dashboard/trends?days=7');
        $resp->assertOk();
        $data = $resp->json();
        $this->assertIsArray($data);
        $this->assertCount(7, $data, '应返回 7 天数据');
        $today = collect($data)->firstWhere('date', now()->format('Y-m-d'));
        $this->assertNotNull($today, '今天应有数据');
        $this->assertSame(10000, $today['paid_amount']);
    }

    public function test_top_products_returns_ranking(): void
    {
        $this->seedData();
        $resp = $this->withHeaders($this->adminHeaders())->getJson('/api/admin/dashboard/top-products?days=7');
        $resp->assertOk();
        $data = $resp->json();
        $this->assertIsArray($data);
        $this->assertNotEmpty($data);
        $this->assertSame('P', $data[0]['product_name']);
        $this->assertSame(10000, $data[0]['paid_amount']);
    }

    public function test_top_channels_returns_ranking(): void
    {
        $this->seedData();
        $resp = $this->withHeaders($this->adminHeaders())->getJson('/api/admin/dashboard/top-channels?days=7');
        $resp->assertOk();
        $data = $resp->json();
        $this->assertIsArray($data);
        $this->assertNotEmpty($data);
        $this->assertSame('alipay', $data[0]['channel']);
        $this->assertEquals(100, $data[0]['success_rate']);
    }
}
