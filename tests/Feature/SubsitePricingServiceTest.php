<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Merchant;
use App\Models\Product;
use App\Models\SubsiteProductSetting;
use App\Models\User;
use App\Support\SubsitePricingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SubsitePricingServiceTest extends TestCase
{
    use RefreshDatabase;

    private function makeSubsite(): Merchant
    {
        $u = User::factory()->create();
        return Merchant::create([
            'user_id' => $u->id, 'name' => 'Sub', 'slug' => 'sub' . uniqid(),
            'status' => 1, 'commission_rate' => 0,
            'settings' => ['is_subsite' => true, 'default_markup_percent' => 0, 'max_markup_percent' => 50],
        ]);
    }

    private function makeProduct(int $price): Product
    {
        $u = User::factory()->create();
        $m = Merchant::create(['user_id' => $u->id, 'name' => 'Main', 'slug' => 'm' . uniqid(), 'status' => 1, 'commission_rate' => 0]);
        $c = Category::create(['merchant_id' => $m->id, 'name' => 'C', 'slug' => 'c' . uniqid(), 'sort' => 0]);
        return Product::create([
            'merchant_id' => $m->id, 'category_id' => $c->id, 'name' => 'P', 'slug' => 'p' . uniqid(),
            'price' => $price, 'stock_type' => 'card', 'delivery_mode' => 'status', 'status' => true, 'sort' => 0,
        ]);
    }

    public function test_inherit_mode_returns_base_price(): void
    {
        $subsite = $this->makeSubsite();
        $product = $this->makeProduct(10000);
        $r = app(SubsitePricingService::class)->resolveUnitPrice($product, null, $subsite);
        $this->assertSame(10000, $r['price']);
        $this->assertSame('inherit', $r['mode']);
    }

    public function test_markup_percent_mode(): void
    {
        $subsite = $this->makeSubsite();
        $product = $this->makeProduct(10000);
        SubsiteProductSetting::create([
            'merchant_id' => $subsite->id, 'product_id' => $product->id, 'sku_id' => 0,
            'is_listed' => true, 'pricing_mode' => 'markup_percent', 'markup_percent' => 10,
        ]);
        $r = app(SubsitePricingService::class)->resolveUnitPrice($product, null, $subsite);
        $this->assertSame(11000, $r['price']);
        $this->assertSame('markup_percent', $r['mode']);
    }

    public function test_fixed_markup_mode(): void
    {
        $subsite = $this->makeSubsite();
        $product = $this->makeProduct(10000);
        SubsiteProductSetting::create([
            'merchant_id' => $subsite->id, 'product_id' => $product->id, 'sku_id' => 0,
            'is_listed' => true, 'pricing_mode' => 'fixed_markup', 'fixed_markup_amount' => 500,
        ]);
        $r = app(SubsitePricingService::class)->resolveUnitPrice($product, null, $subsite);
        $this->assertSame(10500, $r['price']);
    }

    public function test_fixed_price_mode(): void
    {
        $subsite = $this->makeSubsite();
        $product = $this->makeProduct(10000);
        SubsiteProductSetting::create([
            'merchant_id' => $subsite->id, 'product_id' => $product->id, 'sku_id' => 0,
            'is_listed' => true, 'pricing_mode' => 'fixed_price', 'fixed_price_amount' => 15000,
        ]);
        $r = app(SubsitePricingService::class)->resolveUnitPrice($product, null, $subsite);
        $this->assertSame(15000, $r['price']);
    }

    public function test_default_markup_percent_when_no_setting(): void
    {
        $subsite = $this->makeSubsite();
        $subsite->update(['settings' => ['is_subsite' => true, 'default_markup_percent' => 15, 'max_markup_percent' => 50]]);
        $product = $this->makeProduct(10000);
        $r = app(SubsitePricingService::class)->resolveUnitPrice($product, null, $subsite);
        $this->assertSame(11500, $r['price']);
        $this->assertSame('profile', $r['source']);
    }
}
