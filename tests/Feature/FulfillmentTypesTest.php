<?php

namespace Tests\Feature;

use App\Models\Currency;
use App\Models\Merchant;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Support\FulfillmentService;
use App\Support\OrderService;
use App\Support\StorefrontConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class FulfillmentTypesTest extends TestCase
{
    use RefreshDatabase;

    private Merchant $merchant;

    protected function setUp(): void
    {
        parent::setUp();
        Currency::updateOrCreate(['code' => 'CNY'], [
            'name' => '人民币', 'symbol' => '¥', 'symbol_position' => 'before',
            'decimal_places' => 2, 'exchange_rate' => '1', 'is_base' => true, 'is_enabled' => true,
        ]);
        $user = User::factory()->create();
        $this->merchant = Merchant::create([
            'name' => '主站', 'slug' => 'main-'.uniqid(), 'user_id' => $user->id, 'settings' => [],
        ]);
        StorefrontConfig::setMany(['trade_captcha' => false, 'mail_enabled' => false, 'sms_enabled' => false]);
        Cache::flush();
    }

    private function product(string $type, array $extra = []): Product
    {
        return Product::create(array_merge([
            'merchant_id' => $this->merchant->id,
            'name' => '商品-'.$type,
            'slug' => $type.'-'.uniqid(),
            'price' => 1000,
            'factory_price' => 500,
            'stock_type' => 'card',
            'fulfillment_type' => $type,
            'status' => 1,
        ], $extra));
    }

    public function test_fixed_content_is_snapshotted_and_delivered_without_cards(): void
    {
        $product = $this->product(Product::FULFILLMENT_FIXED, [
            'delivery_message' => "固定下载地址\n密码:1234",
            'leave_message' => '<p>使用前先解压</p>',
        ]);
        $order = app(OrderService::class)->createOrder($product->id, null, 2, ['contact' => null]);
        $product->update(['delivery_message' => '后来修改的内容']);

        app(OrderService::class)->markPaid($order->order_no);

        $fresh = $order->fresh();
        $this->assertSame(Product::FULFILLMENT_FIXED, $fresh->fulfillment_type_snapshot);
        $this->assertSame('delivered', $fresh->delivery_status);
        $this->assertSame("固定下载地址\n密码:1234", $fresh->orderDeliveries()->value('card_content'));
        $this->assertSame('<p>使用前先解压</p>', $fresh->instructions_snapshot);
        $this->assertSame(-1, $product->availableStock());
    }

    public function test_manual_order_stays_pending_until_admin_fulfills_once(): void
    {
        $product = $this->product(Product::FULFILLMENT_MANUAL);
        $order = app(OrderService::class)->createOrder($product->id, null, 1, ['contact' => null]);
        app(OrderService::class)->markPaid($order->order_no);

        $this->assertSame('pending', $order->fresh()->delivery_status);
        $service = app(FulfillmentService::class);
        $this->assertTrue($service->fulfill($order, ['订单专属账号'], 'manual'));
        $this->assertFalse($service->fulfill($order, ['重复内容'], 'manual'));
        $this->assertSame('delivered', $order->fresh()->delivery_status);
        $this->assertSame(['订单专属账号'], $order->orderDeliveries()->pluck('card_content')->all());
        $this->assertSame(-1, $product->availableStock());
    }

    public function test_admin_manual_fulfillment_endpoint_rejects_duplicate_submission(): void
    {
        Role::firstOrCreate(['name' => 'super_admin']);
        $admin = User::factory()->create();
        $admin->assignRole('super_admin');
        $token = $admin->createToken('admin-test')->plainTextToken;
        $product = $this->product(Product::FULFILLMENT_MANUAL);
        $order = Order::create([
            'order_no' => 'MANUAL-'.uniqid(), 'merchant_id' => $this->merchant->id,
            'product_id' => $product->id, 'quantity' => 1, 'amount' => 1000,
            'status' => 'paid', 'delivery_status' => 'pending',
            'fulfillment_type_snapshot' => Product::FULFILLMENT_MANUAL, 'paid_at' => now(),
        ]);

        $headers = ['Authorization' => 'Bearer '.$token];
        $this->withHeaders($headers)->postJson("/api/admin/orders/{$order->id}/fulfill", [
            'content' => '人工发货内容',
        ])->assertOk()->assertJsonPath('delivery_status', 'delivered');

        $this->withHeaders($headers)->postJson("/api/admin/orders/{$order->id}/fulfill", [
            'content' => '覆盖原内容',
        ])->assertStatus(409);
        $this->assertSame(['人工发货内容'], $order->orderDeliveries()->pluck('card_content')->all());
    }

    public function test_admin_rejects_invalid_local_fulfillment_configuration(): void
    {
        Role::firstOrCreate(['name' => 'super_admin']);
        $admin = User::factory()->create();
        $admin->assignRole('super_admin');
        $headers = ['Authorization' => 'Bearer '.$admin->createToken('admin-test')->plainTextToken];
        $product = $this->product(Product::FULFILLMENT_AUTO_CARD);

        $this->withHeaders($headers)->putJson("/api/admin/products/{$product->id}", [
            'fulfillment_type' => Product::FULFILLMENT_FIXED,
            'delivery_message' => '',
        ])->assertUnprocessable()->assertJsonValidationErrors('delivery_message');

        $this->withHeaders($headers)->putJson("/api/admin/products/{$product->id}", [
            'fulfillment_type' => Product::FULFILLMENT_UPSTREAM,
        ])->assertUnprocessable()->assertJsonValidationErrors('fulfillment_type');
    }
}
