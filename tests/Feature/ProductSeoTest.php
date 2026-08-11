<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Currency;
use App\Models\Merchant;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * 商品 SEO 字段:后台可保存,前台详情返回自动组合的 seo(title/keywords/description)。
 */
class ProductSeoTest extends TestCase
{
    use RefreshDatabase;

    private function adminHeaders(): array
    {
        foreach (['super_admin', 'merchant', 'user'] as $r) {
            Role::firstOrCreate(['name' => $r]);
        }
        $user = User::factory()->create();
        $user->assignRole('super_admin');

        return ['Authorization' => 'Bearer '.$user->createToken('test')->plainTextToken];
    }

    private function seedBase(): void
    {
        Currency::firstOrCreate(['code' => 'CNY'], [
            'name' => '人民币', 'symbol' => '¥', 'symbol_position' => 'before',
            'decimal_places' => 2, 'exchange_rate' => '1', 'is_base' => true,
            'is_enabled' => true, 'sort' => 0,
        ]);
        Cache::flush();
    }

    private function makeProduct(): Product
    {
        $u = User::factory()->create();
        $m = Merchant::firstOrCreate(['slug' => 'default'], ['user_id' => $u->id, 'name' => '主站', 'status' => 1, 'commission_rate' => 0]);
        $c = Category::create(['merchant_id' => $m->id, 'name' => '谷歌邮箱', 'slug' => 'gmail', 'sort' => 0]);

        return Product::create([
            'merchant_id' => $m->id, 'category_id' => $c->id, 'name' => '美区Gmail带2FA',
            'slug' => 'gmail-2fa-'.uniqid(), 'price' => 1000, 'factory_price' => 600,
            'description' => '<p>美区谷歌邮箱,带二次验证,稳定可靠。</p>',
            'leave_message' => '<p>付款后才能看到的教程</p>',
            'stock_type' => 'card', 'delivery_mode' => 'status', 'status' => true, 'sort' => 0,
        ]);
    }

    public function test_admin_can_save_custom_seo_fields(): void
    {
        $this->seedBase();
        $p = $this->makeProduct();

        $resp = $this->withHeaders($this->adminHeaders())
            ->putJson('/api/admin/products/'.$p->id, [
                'name' => $p->name,
                'seo_title' => '美区Gmail账号带2FA',
                'seo_keywords' => 'gmail,美区,2FA,账号',
                'seo_description' => '美区谷歌邮箱账号,带二次验证。',
            ]);
        $resp->assertOk();

        $fresh = $p->fresh();
        $this->assertSame('美区Gmail账号带2FA', $fresh->seo_title);
        $this->assertSame('gmail,美区,2FA,账号', $fresh->seo_keywords);
        $this->assertSame('美区谷歌邮箱账号,带二次验证。', $fresh->seo_description);
    }

    public function test_admin_can_explicitly_clear_public_detail_and_paid_instructions(): void
    {
        $this->seedBase();
        $product = $this->makeProduct();

        $this->withHeaders($this->adminHeaders())
            ->putJson('/api/admin/products/'.$product->id, [
                'name' => $product->name,
                'description' => '',
                'leave_message' => '',
            ])
            ->assertOk();

        $fresh = $product->fresh();
        $this->assertSame('', $fresh->description);
        $this->assertSame('', $fresh->leave_message);
    }

    public function test_storefront_detail_returns_auto_composed_seo(): void
    {
        $this->seedBase();
        $p = $this->makeProduct();

        $resp = $this->getJson('/api/products/'.$p->slug);
        $resp->assertOk();

        $seo = $resp->json('seo');
        $this->assertSame($p->name, $seo['title']);
        // 关键词自动组合:分类名 + 商品名
        $this->assertStringContainsString('谷歌邮箱', $seo['keywords']);
        $this->assertStringContainsString($p->name, $seo['keywords']);
        // 描述自动取商品描述摘要(去 HTML)
        $this->assertStringContainsString('美区谷歌邮箱', $seo['description']);
        $resp->assertJsonMissingPath('leave_message')
            ->assertJsonMissingPath('instructions');
    }

    public function test_storefront_detail_uses_custom_seo_when_set(): void
    {
        $this->seedBase();
        $p = $this->makeProduct();
        $p->update([
            'seo_title' => '自定义标题',
            'seo_keywords' => '自定义关键词',
            'seo_description' => '自定义描述',
        ]);

        $resp = $this->getJson('/api/products/'.$p->slug);
        $seo = $resp->json('seo');
        $this->assertSame('自定义标题', $seo['title']);
        $this->assertSame('自定义关键词', $seo['keywords']);
        $this->assertSame('自定义描述', $seo['description']);
    }
}
