<?php

namespace Tests\Feature;

use App\Models\Card;
use App\Models\Merchant;
use App\Models\Order;
use App\Models\Product;
use App\Models\SupplySource;
use App\Models\User;
use App\Supply\UpstreamOrderService;
use App\Support\StorefrontConfig;
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
        StorefrontConfig::setMany(['supply_enabled' => true]);
        $merchant = $this->makeMerchant();
        $source = SupplySource::create(['name' => 'S', 'driver' => 'dujiao_next', 'base_url' => 'https://x.com', 'credentials' => [], 'status' => 'active']);
        $product = Product::create(['merchant_id' => $merchant->id, 'name' => 'P', 'slug' => 'p1', 'price' => 500, 'factory_price' => 400, 'stock_type' => 'card', 'status' => 1, 'upstream_source_id' => $source->id, 'upstream_product_code' => 'UP1']);
        $order = Order::create(['order_no' => 'O1', 'merchant_id' => $merchant->id, 'product_id' => $product->id, 'quantity' => 2, 'amount' => 1000, 'status' => 'paid', 'delivery_status' => 'pending', 'paid_at' => now()]);

        app(UpstreamOrderService::class)->writeCards($order, ['CARD-A', 'CARD-B']);

        $this->assertSame('delivered', $order->fresh()->delivery_status);
        // Card.content 加密存储(非明文),plainContent() 解密回原文
        $cards = \App\Models\Card::where('order_id', $order->id)->get();
        $this->assertCount(2, $cards);
        $plainContents = $cards->map(fn ($c) => $c->plainContent())->toArray();
        $this->assertContains('CARD-A', $plainContents);
        $this->assertContains('CARD-B', $plainContents);
        $this->assertNotEquals('CARD-A', $cards->first()->content); // 加密后非明文
        // OrderDelivery 明文快照(顾客订单页读这里)
        $this->assertDatabaseHas('order_deliveries', ['order_id' => $order->id, 'card_content' => 'CARD-A']);
        $this->assertDatabaseHas('order_deliveries', ['order_id' => $order->id, 'card_content' => 'CARD-B']);
    }

    public function test_write_cards_idempotent(): void
    {
        StorefrontConfig::setMany(['supply_enabled' => true]);
        $merchant = $this->makeMerchant();
        $source = SupplySource::create(['name' => 'S', 'driver' => 'dujiao_next', 'base_url' => 'https://x.com', 'credentials' => [], 'status' => 'active']);
        $product = Product::create(['merchant_id' => $merchant->id, 'name' => 'P', 'slug' => 'p2', 'price' => 500, 'factory_price' => 400, 'stock_type' => 'card', 'status' => 1, 'upstream_source_id' => $source->id, 'upstream_product_code' => 'UP2']);
        $order = Order::create(['order_no' => 'O2', 'merchant_id' => $merchant->id, 'product_id' => $product->id, 'quantity' => 1, 'amount' => 500, 'status' => 'paid', 'delivery_status' => 'delivered', 'paid_at' => now()]);

        // 已 delivered,再写不应重复
        app(UpstreamOrderService::class)->writeCards($order, ['DUP']);
        $this->assertDatabaseMissing('cards', ['content' => 'DUP']);
    }
}
