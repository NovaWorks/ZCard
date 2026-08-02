<?php

namespace Tests\Feature;

use App\Models\Card;
use App\Models\Merchant;
use App\Models\Product;
use App\Models\SupplierAccount;
use App\Models\User;
use App\Supply\HmacSigner;
use App\Support\StorefrontConfig;
use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;

class SupplyOrderApiTest extends TestCase
{
    use RefreshDatabase;

    private function makeMerchant(): Merchant
    {
        $user = User::factory()->create();
        return Merchant::create(['name' => 'M', 'slug' => 'm' . uniqid(), 'user_id' => $user->id, 'settings' => []]);
    }

    public function test_create_order_returns_cards(): void
    {
        StorefrontConfig::setMany(['supply_enabled' => true, 'supply_nonce_store' => 'cache']);
        $merchant = $this->makeMerchant();
        $account = SupplierAccount::create(['name' => 'A', 'api_key' => 'ak', 'api_secret' => Crypt::encryptString('sk'), 'balance' => 100000, 'status' => 'active']);
        $product = Product::create(['merchant_id' => $merchant->id, 'name' => 'P', 'slug' => 'p1', 'price' => 500, 'factory_price' => 500, 'stock_type' => 'card', 'status' => 1]);
        Card::create(['product_id' => $product->id, 'content' => 'SECRET-1', 'content_hash' => hash('sha256', 'SECRET-1'), 'status' => Card::STATUS_UNUSED]);

        $body = ['product_id' => $product->id, 'quantity' => 1, 'downstream_order_no' => 'D1'];
        $bodyStr = json_encode($body);
        $path = '/api/supply/orders';
        $ts = (string) time(); $nonce = 'n' . uniqid();
        $ss = HmacSigner::buildSignString('POST', $path, $ts, $nonce, md5($bodyStr));
        $headers = [
            'X-Supply-Key' => 'ak', 'X-Supply-Timestamp' => $ts, 'X-Supply-Nonce' => $nonce,
            'X-Supply-Signature' => HmacSigner::sign('sk', $ss),
        ];

        $resp = $this->withHeaders($headers)->postJson($path, $body);

        $resp->assertStatus(201)->assertJsonPath('ok', true);
        $this->assertContains('SECRET-1', $resp->json('fulfillment.cards'));
        $this->assertSame(99500, (int) $account->fresh()->balance);
    }

    public function test_insufficient_balance_returns_402(): void
    {
        StorefrontConfig::setMany(['supply_enabled' => true, 'supply_nonce_store' => 'cache']);
        $merchant = $this->makeMerchant();
        $account = SupplierAccount::create(['name' => 'A', 'api_key' => 'ak', 'api_secret' => Crypt::encryptString('sk'), 'balance' => 100, 'status' => 'active']);
        $product = Product::create(['merchant_id' => $merchant->id, 'name' => 'P', 'slug' => 'p2', 'price' => 500, 'factory_price' => 500, 'stock_type' => 'card', 'status' => 1]);
        Card::create(['product_id' => $product->id, 'content' => 'C', 'content_hash' => hash('sha256', 'C'), 'status' => Card::STATUS_UNUSED]);

        $body = ['product_id' => $product->id, 'quantity' => 1, 'downstream_order_no' => 'D2'];
        $bodyStr = json_encode($body);
        $ts = (string) time(); $nonce = 'n' . uniqid();
        $ss = HmacSigner::buildSignString('POST', '/api/supply/orders', $ts, $nonce, md5($bodyStr));
        $headers = ['X-Supply-Key' => 'ak', 'X-Supply-Timestamp' => $ts, 'X-Supply-Nonce' => $nonce, 'X-Supply-Signature' => HmacSigner::sign('sk', $ss)];

        $resp = $this->withHeaders($headers)->postJson('/api/supply/orders', $body);
        $resp->assertStatus(402)->assertJsonPath('error_code', 'insufficient_balance');
    }
}
