<?php

namespace Tests\Feature;

use App\Models\Card;
use App\Models\Merchant;
use App\Models\Product;
use App\Models\SupplierAccount;
use App\Models\SupplierProductPrice;
use App\Models\User;
use App\Supply\HmacSigner;
use App\Support\StorefrontConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SupplyProductApiTest extends TestCase
{
    use RefreshDatabase;

    private function makeMerchant(): Merchant
    {
        $user = User::factory()->create();

        return Merchant::create(['name' => 'M', 'slug' => 'm'.uniqid(), 'user_id' => $user->id, 'settings' => []]);
    }

    private function signedGet(SupplierAccount $a, string $path): array
    {
        // getJson() 默认发送 body=[];签名需匹配实际 body 的 md5。
        $ts = (string) time();
        $nonce = 'n'.uniqid();
        $ss = HmacSigner::buildSignString('GET', $path, $ts, $nonce, md5('[]'));

        return [
            'X-Supply-Key' => $a->api_key,
            'X-Supply-Timestamp' => $ts,
            'X-Supply-Nonce' => $nonce,
            'X-Supply-Signature' => HmacSigner::sign($a->getRawOriginal('api_secret'), $ss),
        ];
    }

    public function test_products_return_special_price_for_account(): void
    {
        StorefrontConfig::setMany(['supply_enabled' => true, 'supply_nonce_store' => 'cache']);
        $merchant = $this->makeMerchant();
        $account = SupplierAccount::create(['name' => 'A', 'api_key' => 'ak', 'api_secret' => 'sk', 'status' => 'active']);
        $product = Product::create([
            'merchant_id' => $merchant->id, 'name' => 'P', 'slug' => 'p1', 'price' => 800,
            'factory_price' => 500, 'stock_type' => 'card', 'status' => 1,
        ]);
        SupplierProductPrice::create([
            'supplier_account_id' => $account->id, 'product_id' => $product->id, 'sku_id' => null, 'price' => 460,
        ]);

        $resp = $this->withHeaders($this->signedGet($account, '/api/supply/products'))->getJson('/api/supply/products');

        $resp->assertOk();
        $this->assertSame(460, $resp->json('items.0.price'));
    }

    public function test_show_returns_product_with_price(): void
    {
        StorefrontConfig::setMany(['supply_enabled' => true, 'supply_nonce_store' => 'cache']);
        $merchant = $this->makeMerchant();
        $account = SupplierAccount::create(['name' => 'A', 'api_key' => 'ak', 'api_secret' => 'sk', 'status' => 'active']);
        $product = Product::create([
            'merchant_id' => $merchant->id, 'name' => 'P', 'slug' => 'p2', 'price' => 800,
            'factory_price' => 500, 'stock_type' => 'card', 'status' => 1,
            'leave_message' => '<p>下游付款后教程</p>',
        ]);

        $resp = $this->withHeaders($this->signedGet($account, "/api/supply/products/{$product->id}"))
            ->getJson("/api/supply/products/{$product->id}");

        $resp->assertOk()->assertJsonPath('ok', true);
        $this->assertSame(500, $resp->json('product.price')); // factory_price fallback
        $resp->assertJsonMissingPath('product.instructions');
    }

    public function test_stock_returns_available_count(): void
    {
        StorefrontConfig::setMany(['supply_enabled' => true, 'supply_nonce_store' => 'cache']);
        $merchant = $this->makeMerchant();
        $account = SupplierAccount::create(['name' => 'A', 'api_key' => 'ak', 'api_secret' => 'sk', 'status' => 'active']);
        $product = Product::create([
            'merchant_id' => $merchant->id, 'name' => 'P', 'slug' => 'p3', 'price' => 800,
            'factory_price' => 500, 'stock_type' => 'card', 'status' => 1,
        ]);
        Card::create(['product_id' => $product->id, 'content' => 'C1', 'content_hash' => hash('sha256', 'C1'), 'status' => Card::STATUS_UNUSED]);
        Card::create(['product_id' => $product->id, 'content' => 'C2', 'content_hash' => hash('sha256', 'C2'), 'status' => Card::STATUS_UNUSED]);

        $resp = $this->withHeaders($this->signedGet($account, "/api/supply/products/{$product->id}/stock"))
            ->getJson("/api/supply/products/{$product->id}/stock");

        $resp->assertOk();
        $this->assertSame(2, $resp->json('stock'));
    }
}
