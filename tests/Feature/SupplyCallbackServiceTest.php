<?php

namespace Tests\Feature;

use App\Models\Merchant;
use App\Models\Order;
use App\Models\OrderDelivery;
use App\Models\Product;
use App\Models\SupplierAccount;
use App\Models\SupplyOrder;
use App\Models\User;
use App\Supply\SupplyCallbackService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SupplyCallbackServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_delivered_supply_order_callback_uses_paid_fulfillment_structure(): void
    {
        Http::fake(['https://1.1.1.1/*' => Http::response(['ok' => true])]);
        $user = User::factory()->create();
        $merchant = Merchant::create(['name' => 'M', 'slug' => 'm'.uniqid(), 'user_id' => $user->id, 'settings' => []]);
        $product = Product::create([
            'merchant_id' => $merchant->id, 'name' => 'P', 'slug' => 'p'.uniqid(), 'price' => 500,
            'factory_price' => 300, 'stock_type' => 'card', 'fulfillment_type' => 'manual', 'status' => 1,
        ]);
        $order = Order::create([
            'order_no' => 'SUP-CALLBACK', 'merchant_id' => $merchant->id, 'product_id' => $product->id,
            'quantity' => 1, 'amount' => 500, 'status' => 'paid', 'delivery_status' => 'delivered',
            'fulfillment_type_snapshot' => 'manual', 'instructions_snapshot' => '<p>教程</p>',
            'source' => 'supply', 'paid_at' => now(),
        ]);
        OrderDelivery::create([
            'order_id' => $order->id, 'product_id' => $product->id, 'card_content' => '专属内容',
            'delivered_mode' => 'manual', 'delivered_at' => now(),
        ]);
        $account = SupplierAccount::create([
            'name' => 'A', 'api_key' => 'ak', 'api_secret' => Crypt::encryptString('sk'),
            'balance' => 0, 'status' => 'active', 'approved' => true,
        ]);
        $supplyOrder = SupplyOrder::create([
            'supplier_account_id' => $account->id, 'order_id' => $order->id,
            'downstream_order_no' => 'DOWN-CALLBACK', 'fulfillment_mode' => 'async',
            'callback_url' => 'https://1.1.1.1/api/supply/callback', 'callback_status' => 'pending',
        ]);

        app(SupplyCallbackService::class)->sendForOrder($order);

        $this->assertSame(SupplyOrder::CALLBACK_SENT, $supplyOrder->fresh()->callback_status);
        Http::assertSent(function (Request $request) {
            return $request->url() === 'https://1.1.1.1/api/supply/callback'
                && $request['status'] === 'delivered'
                && $request['fulfillment']['cards'] === ['专属内容']
                && $request['fulfillment']['instructions'] === '<p>教程</p>'
                && $request->hasHeader('X-Supply-Signature');
        });
    }
}
