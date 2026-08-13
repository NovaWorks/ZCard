<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Currency;
use App\Models\Merchant;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * price_manual 标记语义(历史加价跟随):
 * 仅实际改价才标记保护;编辑其他字段不误标;可显式恢复跟随。
 */
class ProductPriceManualTest extends TestCase
{
    use RefreshDatabase;

    private function makeProduct(): Product
    {
        $user = User::factory()->create();
        Merchant::query()->firstOrCreate(
            ['id' => 1],
            ['name' => '主站', 'slug' => 'main-'.uniqid(), 'user_id' => $user->id, 'settings' => []],
        );
        Currency::firstOrCreate(['code' => 'CNY'], ['name' => '人民币', 'symbol' => '¥', 'symbol_position' => 'before', 'decimal_places' => 2, 'exchange_rate' => '1', 'is_base' => true, 'is_enabled' => true, 'sort' => 0]);
        $category = Category::create(['merchant_id' => 1, 'name' => 'C', 'slug' => 'c-'.uniqid(), 'sort' => 0]);

        return Product::create([
            'merchant_id' => 1, 'category_id' => $category->id, 'name' => 'P', 'slug' => 'p-'.uniqid(),
            'price' => 1000, 'factory_price' => 600, 'stock_type' => 'card', 'status' => true, 'sort' => 0,
        ]);
    }

    private function headers(): array
    {
        foreach (['super_admin', 'merchant', 'user'] as $r) {
            Role::firstOrCreate(['name' => $r]);
        }
        $user = User::factory()->create();
        $user->assignRole('super_admin');

        return ['Authorization' => 'Bearer '.$user->createToken('test')->plainTextToken];
    }

    public function test_editing_other_fields_without_price_change_does_not_mark_manual(): void
    {
        $product = $this->makeProduct();

        // 前端编辑表单总是提交 price(原值),只改名称
        $this->withHeaders($this->headers())
            ->putJson("/api/admin/products/{$product->id}", [
                'name' => '新名称',
                'price' => 1000,
                'factory_price' => 600,
            ])->assertOk();

        $this->assertFalse((bool) $product->fresh()->price_manual, '未改价不应标记保护');
    }

    public function test_actual_price_change_marks_manual(): void
    {
        $product = $this->makeProduct();

        $this->withHeaders($this->headers())
            ->putJson("/api/admin/products/{$product->id}", [
                'price' => 1500,
                'factory_price' => 600,
            ])->assertOk();

        $this->assertTrue((bool) $product->fresh()->price_manual, '改价应标记保护');
    }

    public function test_manual_flag_can_be_reset_explicitly(): void
    {
        $product = $this->makeProduct();
        $product->update(['price_manual' => true]);

        // 前端「跟随上游调价」开关 → price_manual=false 显式解除
        $this->withHeaders($this->headers())
            ->putJson("/api/admin/products/{$product->id}", [
                'price' => 1000,
                'price_manual' => false,
            ])->assertOk();

        $this->assertFalse((bool) $product->fresh()->price_manual);
    }
}
