<?php

namespace Tests\Feature;

use App\Models\Card;
use App\Models\Merchant;
use App\Models\Order;
use App\Models\Product;
use App\Models\SupplySource;
use App\Models\User;
use App\Supply\UpstreamOrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UpstreamOrderServiceTest extends TestCase
{
    use RefreshDatabase;

    private function makeMerchant(): Merchant
    {
        $user = User::factory()->create();
        return Merchant::create(['name' => 'M', 'slug' => 'm' . uniqid(), 'user_id' => $user->id, 'settings' => []]);
    }

    public function test_write_cards_marks_order_delivered(): void
    {
        config(['zcard.features.supply' => true]);
        $merchant = $this->makeMerchant();
        $source = SupplySource::create(['name' => 'S', 'driver' => 'dujiao_next', 'base_url' => 'https://x.com', 'credentials' => [], 'status' => 'active']);
        $product = Product::create(['merchant_id' => $merchant->id, 'name' => 'P', 'slug' => 'p1', 'price' => 500, 'factory_price' => 400, 'stock_type' => 'card', 'status' => 1, 'upstream_source_id' => $source->id, 'upstream_product_code' => 'UP1']);
        $order = Order::create(['order_no' => 'O1', 'merchant_id' => $merchant->id, 'product_id' => $product->id, 'quantity' => 2, 'amount' => 1000, 'status' => 'paid', 'delivery_status' => 'pending', 'paid_at' => now()]);

        app(UpstreamOrderService::class)->writeCards($order, ['CARD-A', 'CARD-B']);

        $this->assertSame('delivered', $order->fresh()->delivery_status);
        $this->assertDatabaseHas('cards', ['order_id' => $order->id, 'content' => 'CARD-A']);
        $this->assertDatabaseHas('cards', ['order_id' => $order->id, 'content' => 'CARD-B']);
    }

    public function test_write_cards_idempotent(): void
    {
        config(['zcard.features.supply' => true]);
        $merchant = $this->makeMerchant();
        $source = SupplySource::create(['name' => 'S', 'driver' => 'dujiao_next', 'base_url' => 'https://x.com', 'credentials' => [], 'status' => 'active']);
        $product = Product::create(['merchant_id' => $merchant->id, 'name' => 'P', 'slug' => 'p2', 'price' => 500, 'factory_price' => 400, 'stock_type' => 'card', 'status' => 1, 'upstream_source_id' => $source->id, 'upstream_product_code' => 'UP2']);
        $order = Order::create(['order_no' => 'O2', 'merchant_id' => $merchant->id, 'product_id' => $product->id, 'quantity' => 1, 'amount' => 500, 'status' => 'paid', 'delivery_status' => 'delivered', 'paid_at' => now()]);

        // 已 delivered,再写不应重复
        app(UpstreamOrderService::class)->writeCards($order, ['DUP']);
        $this->assertDatabaseMissing('cards', ['content' => 'DUP']);
    }
}
