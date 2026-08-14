<?php

namespace Tests\Feature;

use App\Models\Bill;
use App\Models\Card;
use App\Models\Merchant;
use App\Models\Order;
use App\Models\Product;
use App\Models\SupplierAccount;
use App\Models\SupplyOrder;
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

    public function test_timeout_auto_refund_returns_balance_payment_once(): void
    {
        $user = User::factory()->create(['balance' => 0]);
        $merchant = $this->makeMerchant();
        $source = SupplySource::create([
            'name' => 'S',
            'driver' => 'zcard',
            'base_url' => 'https://x.com',
            'credentials' => [],
            'settings' => ['failure_action' => 'auto_refund'],
            'status' => 'active',
        ]);
        $product = Product::create([
            'merchant_id' => $merchant->id,
            'name' => 'P',
            'slug' => 'refund-balance',
            'price' => 500,
            'stock_type' => 'card',
            'status' => 1,
            'upstream_source_id' => $source->id,
        ]);
        $order = Order::create([
            'order_no' => 'REFUND-BALANCE',
            'merchant_id' => $merchant->id,
            'user_id' => $user->id,
            'product_id' => $product->id,
            'quantity' => 1,
            'amount' => 500,
            'status' => 'paid',
            'delivery_status' => 'pending',
            'payment_channel' => 'balance',
        ]);

        $service = app(UpstreamOrderService::class);
        $service->handleTimeout($order, $source);
        $service->handleTimeout($order->fresh(), $source);

        $this->assertSame(500, (int) $user->fresh()->balance);
        $this->assertSame('refunded', $order->fresh()->status);
        $this->assertSame('failed', $order->fresh()->delivery_status);
        $this->assertSame(1, Bill::where('order_id', $order->id)->where('type', Bill::TYPE_INCOME)->count());
    }

    public function test_timeout_auto_refund_restores_downstream_supplier_balance_once(): void
    {
        $merchant = $this->makeMerchant();
        $source = SupplySource::create([
            'name' => 'S',
            'driver' => 'zcard',
            'base_url' => 'https://x.com',
            'credentials' => [],
            'settings' => ['failure_action' => 'auto_refund'],
            'status' => 'active',
        ]);
        $product = Product::create([
            'merchant_id' => $merchant->id,
            'name' => 'P',
            'slug' => 'refund-supplier',
            'price' => 500,
            'stock_type' => 'card',
            'status' => 1,
            'upstream_source_id' => $source->id,
        ]);
        $account = SupplierAccount::create([
            'user_id' => User::factory()->create()->id,
            'name' => 'Downstream',
            'api_key' => 'refund_supplier_key',
            'api_secret' => 'secret',
            'balance' => 0,
            'status' => 'active',
            'approved' => true,
        ]);
        $order = Order::create([
            'order_no' => 'REFUND-SUPPLIER',
            'merchant_id' => $merchant->id,
            'product_id' => $product->id,
            'quantity' => 1,
            'amount' => 500,
            'status' => 'paid',
            'delivery_status' => 'pending',
            'source' => 'supply',
        ]);
        SupplyOrder::create([
            'supplier_account_id' => $account->id,
            'order_id' => $order->id,
            'downstream_order_no' => 'DOWNSTREAM-REFUND',
            'fulfillment_mode' => 'async',
        ]);

        $service = app(UpstreamOrderService::class);
        $service->handleTimeout($order, $source);
        $service->handleTimeout($order->fresh(), $source);

        $this->assertSame(500, (int) $account->fresh()->balance);
        $this->assertSame('closed', $order->fresh()->status);
        $this->assertSame('failed', $order->fresh()->delivery_status);
        $this->assertDatabaseCount('supplier_ledger_entries', 1);
    }

    public function test_timeout_does_not_claim_external_gateway_payment_was_refunded(): void
    {
        $merchant = $this->makeMerchant();
        $source = SupplySource::create([
            'name' => 'S',
            'driver' => 'zcard',
            'base_url' => 'https://x.com',
            'credentials' => [],
            'settings' => ['failure_action' => 'auto_refund'],
            'status' => 'active',
        ]);
        $product = Product::create([
            'merchant_id' => $merchant->id,
            'name' => 'P',
            'slug' => 'refund-gateway',
            'price' => 500,
            'stock_type' => 'card',
            'status' => 1,
            'upstream_source_id' => $source->id,
        ]);
        $order = Order::create([
            'order_no' => 'REFUND-GATEWAY',
            'merchant_id' => $merchant->id,
            'product_id' => $product->id,
            'quantity' => 1,
            'amount' => 500,
            'status' => 'paid',
            'delivery_status' => 'pending',
            'payment_channel' => 'epay',
        ]);

        app(UpstreamOrderService::class)->handleTimeout($order, $source);

        $this->assertSame('paid', $order->fresh()->status);
        $this->assertSame('failed', $order->fresh()->delivery_status);
    }
}
