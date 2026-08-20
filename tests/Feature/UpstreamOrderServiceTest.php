<?php

namespace Tests\Feature;

use App\Models\Card;
use App\Models\Merchant;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductSku;
use App\Models\SupplySource;
use App\Models\User;
use App\Supply\UpstreamOrderService;
use App\Support\StorefrontConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class UpstreamOrderServiceTest extends TestCase
{
    use RefreshDatabase;

    private function makeMerchant(): Merchant
    {
        $user = User::factory()->create();

        return Merchant::create(['name' => 'M', 'slug' => 'm'.uniqid(), 'user_id' => $user->id, 'settings' => []]);
    }

    public function test_write_cards_marks_order_delivered(): void
    {
        StorefrontConfig::setMany([
            'supply_enabled' => true,
            // 本用例验证「加密存储」路径,显式开启卡密加密(密钥走 phpunit.xml 固定 CARD_ENCRYPTION_KEY)
            'card_encryption_enabled' => true,
        ]);
        $merchant = $this->makeMerchant();
        $source = SupplySource::create(['name' => 'S', 'driver' => 'dujiao_next', 'base_url' => 'https://x.com', 'credentials' => [], 'status' => 'active']);
        $product = Product::create(['merchant_id' => $merchant->id, 'name' => 'P', 'slug' => 'p1', 'price' => 500, 'factory_price' => 400, 'stock_type' => 'card', 'status' => 1, 'upstream_source_id' => $source->id, 'upstream_product_code' => 'UP1']);
        $order = Order::create(['order_no' => 'O1', 'merchant_id' => $merchant->id, 'product_id' => $product->id, 'quantity' => 2, 'amount' => 1000, 'status' => 'paid', 'delivery_status' => 'pending', 'paid_at' => now()]);

        app(UpstreamOrderService::class)->writeCards($order, ['CARD-A', 'CARD-B']);

        $this->assertSame('delivered', $order->fresh()->delivery_status);
        // Card.content 加密存储(非明文),plainContent() 解密回原文
        $cards = Card::where('order_id', $order->id)->get();
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

    public function test_write_fulfillment_stores_cards_and_instructions_atomically(): void
    {
        StorefrontConfig::setMany(['supply_enabled' => true]);
        $merchant = $this->makeMerchant();
        $source = SupplySource::create(['name' => 'S', 'driver' => 'zcard', 'base_url' => 'https://x.com', 'credentials' => [], 'status' => 'active']);
        $product = Product::create([
            'merchant_id' => $merchant->id, 'name' => 'P', 'slug' => 'p3', 'price' => 500,
            'factory_price' => 400, 'stock_type' => 'card', 'fulfillment_type' => 'upstream',
            'status' => 1, 'upstream_source_id' => $source->id, 'upstream_product_code' => 'UP3',
        ]);
        $order = Order::create([
            'order_no' => 'O3', 'merchant_id' => $merchant->id, 'product_id' => $product->id,
            'quantity' => 1, 'amount' => 500, 'status' => 'paid', 'delivery_status' => 'pending',
            'fulfillment_type_snapshot' => 'upstream', 'paid_at' => now(),
        ]);

        app(UpstreamOrderService::class)->writeFulfillment($order, ['CARD-Z'], '<p>上游付款后教程</p>');

        $fresh = $order->fresh();
        $this->assertSame('delivered', $fresh->delivery_status);
        $this->assertSame('<p>上游付款后教程</p>', $fresh->instructions_snapshot);
        $this->assertDatabaseHas('order_deliveries', ['order_id' => $order->id, 'card_content' => 'CARD-Z']);
    }

    public function test_fetch_passes_order_snapshot_and_replaces_estimated_cost_with_actual_amount(): void
    {
        StorefrontConfig::setMany(['supply_enabled' => true]);
        $merchant = $this->makeMerchant();
        $source = SupplySource::create([
            'name' => 'ACG', 'driver' => 'acg_faka', 'base_url' => 'https://acg.test',
            'credentials' => ['app_id' => '8', 'app_key' => 'secret'], 'status' => 'active',
        ]);
        $product = Product::create([
            'merchant_id' => $merchant->id, 'name' => 'P', 'slug' => 'p4', 'price' => 1200,
            'factory_price' => 800, 'stock_type' => 'card', 'fulfillment_type' => 'upstream',
            'status' => 1, 'upstream_source_id' => $source->id, 'upstream_product_code' => 'UP4',
        ]);
        $skuCode = json_encode(['race' => '美区', 'sku' => ['时长' => '年卡']], JSON_UNESCAPED_UNICODE);
        $sku = ProductSku::create([
            'product_id' => $product->id, 'upstream_sku_code' => $skuCode,
            'name' => '美区 / 年卡', 'price' => 1200, 'status' => true,
        ]);
        $order = Order::create([
            'order_no' => 'O4', 'merchant_id' => $merchant->id, 'product_id' => $product->id,
            'quantity' => 1, 'amount' => 1200, 'cost' => 800, 'status' => 'paid',
            'delivery_status' => 'pending', 'fulfillment_type_snapshot' => 'upstream',
            'contact' => 'buyer@example.com', 'create_device' => 'win',
            'extra' => ['sku_id' => $sku->id, 'control' => ['role' => 'mage']], 'paid_at' => now(),
        ]);
        $sent = null;
        Http::fake(function (ClientRequest $request) use (&$sent) {
            $sent = $request;

            return Http::response([
                'code' => 200,
                'data' => ['amount' => '8.88', 'tradeNo' => 'ACG-T4', 'secret' => 'CARD-4'],
            ]);
        });

        app(UpstreamOrderService::class)->fetchFromUpstream($order, $source);

        $form = $sent->data();
        $this->assertSame('美区', $form['race']);
        $this->assertSame(['时长' => '年卡'], $form['sku']);
        $this->assertSame('buyer@example.com', $form['contact']);
        $this->assertSame('mage', $form['role']);
        $fresh = $order->fresh();
        $this->assertSame(888, (int) $fresh->cost);
        $this->assertSame('ACG-T4', $fresh->upstream_order_id);
        $this->assertSame('delivered', $fresh->delivery_status);
    }
}
