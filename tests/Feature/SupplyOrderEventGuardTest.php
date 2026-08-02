<?php

namespace Tests\Feature;

use App\Events\OrderPaid;
use App\Models\Merchant;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

class SupplyOrderEventGuardTest extends TestCase
{
    use RefreshDatabase;

    private function makeMerchantProduct(): array
    {
        $user = User::factory()->create();
        $merchant = Merchant::create(['name' => 'M', 'slug' => 'm' . uniqid(), 'user_id' => $user->id, 'settings' => []]);
        $product = Product::create([
            'merchant_id' => $merchant->id, 'name' => 'P', 'slug' => 'p' . uniqid(),
            'price' => 1000, 'factory_price' => 800, 'stock_type' => 'card', 'status' => 1,
        ]);
        return [$merchant, $product];
    }

    public function test_supply_order_does_not_create_commission(): void
    {
        config(['zcard.features.distribution' => true, 'zcard.features.supply' => true]);
        [$merchant, $product] = $this->makeMerchantProduct();
        $order = Order::create([
            'order_no' => 'S1', 'merchant_id' => $merchant->id, 'product_id' => $product->id, 'quantity' => 1,
            'amount' => 1000, 'status' => 'paid', 'source' => 'supply', 'paid_at' => now(),
        ]);

        event(new OrderPaid($order));

        $this->assertDatabaseMissing('commissions', ['order_id' => $order->id]);
    }

    public function test_supply_order_does_not_create_subsite_settlement(): void
    {
        config(['zcard.features.sub_site' => true, 'zcard.features.supply' => true]);
        [$merchant, $product] = $this->makeMerchantProduct();
        $order = Order::create([
            'order_no' => 'S2', 'merchant_id' => $merchant->id, 'product_id' => $product->id, 'quantity' => 1,
            'amount' => 1000, 'status' => 'paid', 'source' => 'supply', 'paid_at' => now(),
        ]);

        event(new OrderPaid($order));

        $this->assertDatabaseMissing('subsite_ledger_entries', ['order_id' => $order->id]);
    }
}
