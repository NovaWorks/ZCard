<?php

namespace Tests\Feature;

use App\Models\Category;
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

    public function test_sync_auto_creates_upstream_category(): void
    {
        $source = $this->makeSource([]);
        $service = app(SupplySyncService::class);

        $dto = new UpstreamProduct(
            code: 'UP_CAT_1', name: '同步商品', price: 800, factoryPrice: 500,
            categoryCode: 'CAT_A', categoryName: '上游分类A',
        );
        $product = $service->upsertProduct($source, $dto);

        $this->assertNotNull($product->category_id, '同步商品应自动归入创建的分类,而不是落到"无分类"');
        $category = $product->category;
        $this->assertSame('CAT_A', $category->slug);
        $this->assertSame('上游分类A', $category->name);
        $this->assertSame(1, (int) $category->merchant_id);
    }

    public function test_resync_reuses_existing_upstream_category(): void
    {
        $source = $this->makeSource([]);
        $service = app(SupplySyncService::class);

        $service->upsertProduct($source, new UpstreamProduct(
            code: 'UP_CAT_2', name: 'A', price: 800, factoryPrice: 500,
            categoryCode: 'CAT_B', categoryName: '分类B',
        ));
        // 再次同步同一分类下另一个商品
        $p2 = $service->upsertProduct($source, new UpstreamProduct(
            code: 'UP_CAT_3', name: 'B', price: 800, factoryPrice: 500,
            categoryCode: 'CAT_B', categoryName: '分类B',
        ));

        $this->assertSame(1, Category::where('slug', 'CAT_B')->count(), '分类不应重复创建');
        $this->assertSame($p2->category_id, $p2->category_id);
        // 两个商品归入同一分类
        $catId = Category::where('slug', 'CAT_B')->value('id');
        $this->assertSame($catId, (int) $p2->category_id);
    }

    public function test_category_map_takes_priority_over_auto_create(): void
    {
        $source = $this->makeSource([]);
        $service = app(SupplySyncService::class);
        $localCat = Category::create([
            'merchant_id' => 1, 'name' => '本地分类', 'slug' => 'local-cat', 'sort' => 0, 'status' => 1,
        ]);

        $dto = new UpstreamProduct(
            code: 'UP_CAT_4', name: 'A', price: 800, factoryPrice: 500,
            categoryCode: 'CAT_C', categoryName: '上游分类C',
        );
        $product = $service->upsertProduct($source, $dto, categoryMap: ['CAT_C' => $localCat->id]);

        $this->assertSame($localCat->id, (int) $product->category_id);
        $this->assertNull(Category::where('slug', 'CAT_C')->first(), '显式映射时不应自动创建分类');
    }

    public function test_sync_truncates_oversized_product_name(): void
    {
        $source = $this->makeSource([]);
        $service = app(SupplySyncService::class);

        $longName = '💎【带余额】【高概率可充值】美国-香港-中国-韩国-日本-台湾-澳大利亚-挪威-巴西-沙特阿拉伯-卢森堡-中国大陆-南非-奥地利-阿拉伯联合酋长国-爱尔兰-希腊-瑞士-葡萄牙-中国-法国-德国-英国-西班牙-意大利-荷兰-比利时-波兰-瑞典-丹麦-芬兰-挪威-俄罗斯-乌克兰-土耳其-印度-印尼-泰国-越南-菲律宾-马来西亚-新加坡-新西兰-加拿大-墨西哥-阿根廷-智利-哥伦比亚-秘鲁-埃及-尼日利亚-肯尼亚-摩洛哥-阿联酋-卡塔尔-科威特-巴林-阿曼-约旦-黎巴嫩-以色列-塞浦路斯-马耳他-冰岛-爱尔兰-匈牙利-捷克-斯洛伐克-斯洛文尼亚-克罗地亚-塞尔维亚-保加利亚-罗马尼亚-立陶宛-拉脱维亚-爱沙尼亚-白俄罗斯-摩尔多瓦-格鲁吉亚-亚美尼亚-阿塞拜疆-哈萨克斯坦-乌兹别克斯坦-土库曼斯坦-吉尔吉斯斯坦-塔吉克斯坦-蒙古-朝鲜-斯里兰卡-孟加拉国-巴基斯坦-阿富汗-伊朗-伊拉克-叙利亚-也门-约旦-巴勒斯坦';

        $product = $service->upsertProduct($source, new UpstreamProduct(
            code: 'UP_LONG_1', name: $longName, price: 800, factoryPrice: 500,
        ));

        $this->assertLessThanOrEqual(145, mb_strlen($product->fresh()->name));
        $this->assertLessThanOrEqual(145, mb_strlen($product->fresh()->slug));
    }
}
