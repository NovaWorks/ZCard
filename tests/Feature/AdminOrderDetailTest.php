<?php

namespace Tests\Feature;

use App\Models\Merchant;
use App\Models\Order;
use App\Models\OrderDelivery;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * 后台订单详情必须能查看已发货卡密(含上游拿货卡密)。
 * 回归:OrderDelivery::$hidden = ['card_content'] 曾误伤 Admin/OrderController::show,
 * 导致管理员在订单详情看不到本地/上游发货内容(显式回填修复)。
 */
class AdminOrderDetailTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Role::firstOrCreate(['name' => 'super_admin']);
    }

    private function makePaidOrder(): Order
    {
        $merchant = Merchant::firstOrCreate(
            ['slug' => 'od'],
            ['user_id' => User::factory()->create()->id, 'name' => 'OD', 'status' => 1, 'commission_rate' => 0],
        );
        $product = Product::create([
            'merchant_id' => $merchant->id, 'name' => '上游商品', 'slug' => 'od-'.bin2hex(random_bytes(4)),
            'price' => 100, 'factory_price' => 50, 'status' => 1,
        ]);

        return Order::create([
            'order_no' => 'ORD'.time().bin2hex(random_bytes(2)),
            'merchant_id' => $merchant->id,
            'product_id' => $product->id,
            'quantity' => 2,
            'amount' => 200,
            'cost' => 100,
            'status' => 'paid',
            'paid_at' => now(),
            'delivery_status' => 'delivered',
            'fulfillment_type_snapshot' => 'upstream',
            'upstream_source_id' => null,
        ]);
    }

    public function test_admin_order_detail_includes_delivered_card_content(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('super_admin');

        $order = $this->makePaidOrder();
        OrderDelivery::create([
            'order_id' => $order->id,
            'product_id' => $order->product_id,
            'card_content' => 'UPSTREAM-CARD-AAA',
            'delivered_mode' => 'status',
            'delivered_at' => now(),
        ]);
        OrderDelivery::create([
            'order_id' => $order->id,
            'product_id' => $order->product_id,
            'card_content' => 'UPSTREAM-CARD-BBB',
            'delivered_mode' => 'status',
            'delivered_at' => now(),
        ]);

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/admin/orders/'.$order->id)
            ->assertOk()
            ->assertJsonPath('deliveries.0.card_content', 'UPSTREAM-CARD-AAA')
            ->assertJsonPath('deliveries.1.card_content', 'UPSTREAM-CARD-BBB')
            ->assertJsonCount(2, 'deliveries');
    }

    public function test_order_detail_requires_admin_role(): void
    {
        $order = $this->makePaidOrder();

        // 未认证 → 401
        $this->getJson('/api/admin/orders/'.$order->id)->assertStatus(401);

        // 普通登录用户(非超管) → 403,且响应不含卡密
        $user = User::factory()->create();
        $this->actingAs($user, 'sanctum')
            ->getJson('/api/admin/orders/'.$order->id)
            ->assertStatus(403);
    }
}
