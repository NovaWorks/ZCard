<?php

namespace Tests\Feature;

use App\Models\Merchant;
use App\Models\SupplySource;
use App\Models\User;
use App\Supply\Dto\UpstreamProduct;
use App\Supply\SupplySyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SupplySyncServiceTest extends TestCase
{
    use RefreshDatabase;

    private function makeSource(array $settings = []): SupplySource
    {
        $user = User::factory()->create();
        // 主站(merchant id=1)是 SupplySyncService 写入 products 时硬编码的目标,
        // 此处先确保存在,避免 products.merchant_id 外键约束失败。
        Merchant::query()->firstOrCreate(
            ['id' => 1],
            ['name' => '主站', 'slug' => 'main-'.uniqid(), 'user_id' => $user->id, 'settings' => []],
        );

        return SupplySource::create([
            'name' => 'S', 'driver' => 'dujiao_next', 'base_url' => 'https://x.com',
            'credentials' => [], 'status' => 'active', 'settings' => $settings,
        ]);
    }

    public function test_new_product_created_with_pricing_rule_percent(): void
    {
        $source = $this->makeSource(['default_pricing_mode' => 'percent', 'default_markup_percent' => 10, 'auto_list' => true]);
        $service = app(SupplySyncService::class);

        $dto = new UpstreamProduct(code: 'UP1', name: '上游商品', price: 800, factoryPrice: 500, categoryCode: null);
        $product = $service->upsertProduct($source, $dto);

        $this->assertSame('UP1', $product->upstream_product_code);
        $this->assertSame($source->id, $product->upstream_source_id);
        $this->assertSame(500, (int) $product->factory_price);
        $this->assertSame(550, (int) $product->price); // 500 × 110% = 550
    }

    public function test_resync_updates_factory_price_but_keeps_local_price(): void
    {
        $source = $this->makeSource(['default_pricing_mode' => 'equal']);
        $service = app(SupplySyncService::class);

        // 首次同步,平价
        $p1 = $service->upsertProduct($source, new UpstreamProduct(code: 'UP2', name: 'A', price: 500, factoryPrice: 500));
        $this->assertSame(500, (int) $p1->price);

        // 运营手动改价
        $p1->update(['price' => 999]);

        // 再次同步,上游涨价到 600
        $p2 = $service->upsertProduct($source, new UpstreamProduct(code: 'UP2', name: 'A', price: 600, factoryPrice: 600));
        $this->assertSame(600, (int) $p2->factory_price); // 成本更新
        $this->assertSame(999, (int) $p2->price); // 售价不动(售价保护)
    }

    public function test_inactive_upstream_product_gets_hidden(): void
    {
        $source = $this->makeSource([]);
        $service = app(SupplySyncService::class);
        $p = $service->upsertProduct($source, new UpstreamProduct(code: 'UP3', name: 'A', price: 500, factoryPrice: 500, isActive: true));

        $service->upsertProduct($source, new UpstreamProduct(code: 'UP3', name: 'A', price: 500, factoryPrice: 500, isActive: false));

        $this->assertTrue((bool) $p->fresh()->hide); // 标记下架
    }

    public function test_description_relative_images_get_base_url_prefixed(): void
    {
        $source = $this->makeSource([]);
        $service = app(SupplySyncService::class);

        $dto = new UpstreamProduct(
            code: 'UP4', name: 'A', price: 500, factoryPrice: 500,
            description: '<p>介绍</p><img src="/assets/a.png"><img src="b.png"><img src="https://cdn.example.com/c.png"><img src="//cdn2.example.com/d.png"><img src="data:image/png;base64,xxx">',
        );
        $product = $service->upsertProduct($source, $dto);

        $desc = $product->fresh()->description;
        $this->assertStringContainsString('https://x.com/assets/a.png', $desc);   // 站内绝对路径拼 base_url
        $this->assertStringContainsString('https://x.com/b.png', $desc);          // 相对路径拼 base_url
        $this->assertStringContainsString('https://cdn.example.com/c.png', $desc); // 绝对 URL 不动
        $this->assertStringContainsString('//cdn2.example.com/d.png', $desc);     // 协议相对不动
        $this->assertStringContainsString('data:image/png;base64,xxx', $desc);    // data: 不动
    }
}
