<?php

namespace Tests\Feature;

use App\Models\Merchant;
use App\Models\Product;
use App\Models\SupplySource;
use App\Models\User;
use App\Support\OrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrderControlValidationTest extends TestCase
{
    use RefreshDatabase;

    private function product(): Product
    {
        $user = User::factory()->create();
        $merchant = Merchant::create([
            'name' => '主站', 'slug' => 'main-controls', 'user_id' => $user->id, 'settings' => [],
        ]);
        $source = SupplySource::create([
            'name' => 'ACG', 'driver' => 'acg_faka', 'base_url' => 'https://acg.test',
            'credentials' => ['app_id' => '8', 'app_key' => 'secret'], 'status' => 'active',
        ]);

        return Product::create([
            'merchant_id' => $merchant->id,
            'name' => '动态字段商品',
            'slug' => 'control-product',
            'price' => 1000,
            'factory_price' => 800,
            'stock_type' => 'card',
            'fulfillment_type' => Product::FULFILLMENT_UPSTREAM,
            'status' => true,
            'upstream_source_id' => $source->id,
            'upstream_product_code' => 'CONTROL',
            'control_config' => [[
                'type' => 'select',
                'label' => '角色',
                'name' => 'role',
                'required' => true,
                'options' => ['warrior', 'mage'],
                'regex' => '^(warrior|mage)$',
                'error' => '角色无效',
            ]],
        ]);
    }

    public function test_required_upstream_control_is_validated_before_creating_order(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('请填写角色');

        app(OrderService::class)->createOrder($this->product()->id, null, 1, [
            'contact' => 'buyer@example.com',
            'extra' => [],
        ]);
    }

    public function test_only_declared_valid_controls_are_snapshotted(): void
    {
        $order = app(OrderService::class)->createOrder($this->product()->id, null, 1, [
            'contact' => 'buyer@example.com',
            'extra' => ['role' => 'mage', 'unexpected' => 'drop-me'],
        ]);

        $this->assertSame(['role' => 'mage'], data_get($order->extra, 'control'));
    }
}
