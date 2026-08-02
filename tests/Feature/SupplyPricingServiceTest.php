<?php

namespace Tests\Feature;

use App\Models\Merchant;
use App\Models\Product;
use App\Models\ProductSku;
use App\Models\SupplierAccount;
use App\Models\SupplierProductPrice;
use App\Models\User;
use App\Supply\SupplyPricingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SupplyPricingServiceTest extends TestCase
{
    use RefreshDatabase;

    /**
     * products.merchant_id 有外键约束 → merchants.id,空库直接写 merchant_id=1 会失败。
     * 先建一个真实 Merchant 行(spec §7.4 测试)。与 SupplyModelsTest 同款 helper。
     */
    private function makeMerchant(): Merchant
    {
        return Merchant::create([
            'user_id' => User::factory()->create()->id,
            'name' => 'M', 'slug' => 'm-' . uniqid(),
            'status' => 1, 'commission_rate' => 0,
        ]);
    }

    private function makeProduct(int $factoryPrice = 500): Product
    {
        return Product::create([
            'merchant_id' => $this->makeMerchant()->id,
            'name' => 'P', 'slug' => 'p' . uniqid(),
            'price' => 800, 'factory_price' => $factoryPrice, 'stock_type' => 'card',
            'status' => 1,
        ]);
    }

    public function test_falls_back_to_factory_price_when_no_special_price(): void
    {
        $product = $this->makeProduct(500);
        $account = SupplierAccount::create(['name' => 'A', 'api_key' => 'k', 'api_secret' => 's']);

        $price = app(SupplyPricingService::class)->resolvePrice($account, $product, null);
        $this->assertSame(500, $price);
    }

    public function test_uses_product_level_price(): void
    {
        $product = $this->makeProduct(500);
        $account = SupplierAccount::create(['name' => 'A', 'api_key' => 'k', 'api_secret' => 's']);
        SupplierProductPrice::create([
            'supplier_account_id' => $account->id, 'product_id' => $product->id,
            'sku_id' => null, 'price' => 450,
        ]);

        $price = app(SupplyPricingService::class)->resolvePrice($account, $product, null);
        $this->assertSame(450, $price);
    }

    public function test_sku_level_overrides_product_level(): void
    {
        $product = $this->makeProduct(500);
        $sku = ProductSku::create([
            'product_id' => $product->id, 'name' => '规格A', 'price' => 600, 'stock_type' => 'card', 'status' => 1,
        ]);
        $account = SupplierAccount::create(['name' => 'A', 'api_key' => 'k', 'api_secret' => 's']);
        SupplierProductPrice::create([
            'supplier_account_id' => $account->id, 'product_id' => $product->id,
            'sku_id' => null, 'price' => 450, // 商品级
        ]);
        SupplierProductPrice::create([
            'supplier_account_id' => $account->id, 'product_id' => $product->id,
            'sku_id' => $sku->id, 'price' => 400, // SKU级(更优)
        ]);

        $price = app(SupplyPricingService::class)->resolvePrice($account, $product, $sku);
        $this->assertSame(400, $price);
    }
}
