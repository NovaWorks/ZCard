<?php

namespace Tests\Feature;

use App\Models\Merchant;
use App\Models\Product;
use App\Models\SupplySource;
use App\Models\User;
use App\Support\StorefrontConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ImportProductsFallbackTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['super_admin', 'merchant', 'user'] as $role) {
            Role::firstOrCreate(['name' => $role]);
        }
        Merchant::firstOrCreate(
            ['id' => 1],
            ['name' => '主站', 'slug' => 'main-'.uniqid(), 'user_id' => User::factory()->create()->id, 'settings' => []],
        );
        StorefrontConfig::setMany(['supply_enabled' => true]);
    }

    private function adminToken(): string
    {
        $user = User::factory()->create();
        $user->assignRole('super_admin');

        return $user->createToken('test')->plainTextToken;
    }

    /** 模拟 acg-faka items:返回商品(可指定 code 列表) */
    private function fakeAcgFakaItems(array $codes): void
    {
        $data = [];
        foreach (array_chunk($codes, 6) as $ci => $chunk) {
            $data[] = [
                'id' => $ci + 1,
                'name' => '分类'.($ci + 1),
                'children' => collect($chunk)->map(fn ($code) => [
                    'code' => $code,
                    'name' => "商品{$code}",
                    'price' => '10.00',
                ])->all(),
            ];
        }

        Http::fake([
            '*' => Http::response(['code' => 200, 'msg' => 'success', 'data' => $data]),
        ]);
    }

    /** 16 个全新商品导入(全选) */
    public function test_import_all_16_new_codes_succeeds(): void
    {
        $codes = collect(range(1, 16))->map(fn ($i) => strtoupper(substr(md5("n{$i}"), 0, 16)))->all();
        $this->fakeAcgFakaItems($codes);
        $source = SupplySource::create([
            'name' => 'S', 'driver' => 'acg_faka', 'base_url' => 'https://x.com',
            'credentials' => ['app_id' => '1', 'app_key' => 'k'], 'status' => 'active',
        ]);

        $resp = $this->withToken($this->adminToken())
            ->postJson("/api/admin/supply-sources/{$source->id}/products/import", [
                'codes' => $codes,
                'pricing' => ['mode' => 'equal'],
            ]);

        $resp->assertOk();
        $body = $resp->json();
        fwrite(STDERR, "\n[import-16new] imported={$body['imported']} skipped={$body['skipped']} msg={$body['message']}\n");
        $this->assertSame(16, $body['imported'], '16 个全新商品应全部导入');
        $this->assertSame(0, $body['skipped']);
    }

    /** 混合场景:预置 14 个已导入,上游 16 个(14 旧 + 2 新),全选 16 个导入 */
    public function test_import_mixed_already_imported_and_new(): void
    {
        $codes = collect(range(1, 16))->map(fn ($i) => strtoupper(substr(md5("m{$i}"), 0, 16)))->all();
        $this->fakeAcgFakaItems($codes);
        $source = SupplySource::create([
            'name' => 'S', 'driver' => 'acg_faka', 'base_url' => 'https://x.com',
            'credentials' => ['app_id' => '1', 'app_key' => 'k'], 'status' => 'active',
        ]);

        // 预置 14 个已导入商品
        foreach (array_slice($codes, 0, 14) as $code) {
            Product::create([
                'merchant_id' => 1,
                'name' => "已导入{$code}",
                'slug' => 'p-'.strtolower($code),
                'price' => 1000,
                'factory_price' => 800,
                'stock_type' => 'card',
                'status' => 1,
                'upstream_source_id' => $source->id,
                'upstream_product_code' => $code,
            ]);
        }

        // 全选 16 个(14 已对接 + 2 新货源)导入
        $resp = $this->withToken($this->adminToken())
            ->postJson("/api/admin/supply-sources/{$source->id}/products/import", [
                'codes' => $codes,
                'pricing' => ['mode' => 'equal'],
            ]);

        $resp->assertOk();
        $body = $resp->json();
        fwrite(STDERR, "\n[import-mixed] imported={$body['imported']} skipped={$body['skipped']} msg={$body['message']}\n");
        $this->assertSame(16, $body['imported'], '全选 16 个(含已导入)应全部导入成功');
        $this->assertSame(0, $body['skipped']);
    }

    /** ZCard 上游分页 bug 复现:has_more 写死 false → 只取第 1 页 */
    public function test_zcard_driver_only_fetches_first_page_when_has_more_hardcoded_false(): void
    {
        $page1 = collect(range(1, 50))->map(fn ($i) => ['id' => $i, 'name' => "商品{$i}", 'price' => 1000])->all();
        $page2 = collect(range(51, 55))->map(fn ($i) => ['id' => $i, 'name' => "商品{$i}", 'price' => 1000])->all();

        Http::fake([
            '*/api/supply/products*' => function ($request) use ($page1, $page2) {
                $page = (int) ($request->data()['page'] ?? 1);
                $items = $page === 1 ? $page1 : $page2;

                return Http::response(['ok' => true, 'items' => $items, 'total' => 55, 'page' => $page, 'page_size' => 50]);
            },
        ]);

        $source = SupplySource::create([
            'name' => 'S', 'driver' => 'zcard', 'base_url' => 'https://x.com',
            'credentials' => ['api_key' => 'ak', 'api_secret' => 'sk'], 'status' => 'active',
        ]);

        $resp = $this->withToken($this->adminToken())
            ->getJson("/api/admin/supply-sources/{$source->id}/products/preview");

        $resp->assertOk();
        $total = $resp->json('total');
        fwrite(STDERR, "\n[zcard-preview] total={$total}(期望 55)\n");
        $this->assertSame(55, $total, 'ZCard 上游分页应拉取全部,has_more=false 导致只取第 1 页');
    }

    /** ZCard 上游 16 商品(一页全量),预置 14 个已导入,全选 16 个导入 */
    public function test_zcard_import_16_with_14_preexisting(): void
    {
        $items = collect(range(1, 16))->map(fn ($i) => ['id' => $i, 'name' => "商品{$i}", 'price' => 1000])->all();

        Http::fake([
            '*/api/supply/products*' => Http::response(['ok' => true, 'items' => $items, 'total' => 16, 'page' => 1, 'page_size' => 50]),
            '*/api/supply/categories*' => Http::response(['ok' => true, 'categories' => []]),
        ]);

        $source = SupplySource::create([
            'name' => 'S', 'driver' => 'zcard', 'base_url' => 'https://x.com',
            'credentials' => ['api_key' => 'ak', 'api_secret' => 'sk'], 'status' => 'active',
        ]);

        foreach (range(1, 14) as $i) {
            Product::create([
                'merchant_id' => 1, 'name' => "已导入{$i}", 'slug' => "p-{$i}",
                'price' => 1000, 'factory_price' => 800, 'stock_type' => 'card', 'status' => 1,
                'upstream_source_id' => $source->id, 'upstream_product_code' => (string) $i,
            ]);
        }

        $codes = collect(range(1, 16))->map(fn ($i) => (string) $i)->all();
        $resp = $this->withToken($this->adminToken())
            ->postJson("/api/admin/supply-sources/{$source->id}/products/import", [
                'codes' => $codes,
                'pricing' => ['mode' => 'equal'],
            ]);

        $resp->assertOk();
        $body = $resp->json();
        fwrite(STDERR, "\n[zcard-16-import] imported={$body['imported']} skipped={$body['skipped']} msg={$body['message']}\n");
        $this->assertSame(16, $body['imported']);
        $this->assertSame(0, $body['skipped']);
    }

    /** 复现:导入后 immediately preview,验证 already_imported 是否正确标记 */
    public function test_already_imported_flag_after_import(): void
    {
        $codes = collect(range(1, 16))->map(fn ($i) => strtoupper(substr(md5("a{$i}"), 0, 16)))->all();
        $this->fakeAcgFakaItems($codes);
        $source = SupplySource::create([
            'name' => 'S', 'driver' => 'acg_faka', 'base_url' => 'https://x.com',
            'credentials' => ['app_id' => '1', 'app_key' => 'k'], 'status' => 'active',
        ]);
        $token = $this->adminToken();

        // 第一次 preview:全部新货源
        $preview1 = $this->withToken($token)->getJson("/api/admin/supply-sources/{$source->id}/products/preview");
        $preview1->assertOk();
        $allNew = collect($preview1->json('categories'))->flatMap(fn ($c) => $c['products'])
            ->filter(fn ($p) => ! $p['already_imported'])->count();
        fwrite(STDERR, "\n[preview1] 未对接数量={$allNew}(期望 16)\n");
        $this->assertSame(16, $allNew);

        // 导入全部 16 个
        $imp = $this->withToken($token)->postJson("/api/admin/supply-sources/{$source->id}/products/import", [
            'codes' => $codes, 'pricing' => ['mode' => 'equal'],
        ]);
        fwrite(STDERR, "\n[import] imported={$imp->json('imported')} skipped={$imp->json('skipped')}\n");

        // 第二次 preview:应该全部已对接
        $preview2 = $this->withToken($token)->getJson("/api/admin/supply-sources/{$source->id}/products/preview");
        $preview2->assertOk();
        $newAfter = collect($preview2->json('categories'))->flatMap(fn ($c) => $c['products'])
            ->filter(fn ($p) => ! $p['already_imported'])->count();
        $importedAfter = collect($preview2->json('categories'))->flatMap(fn ($c) => $c['products'])
            ->filter(fn ($p) => $p['already_imported'])->count();
        fwrite(STDERR, "\n[preview2] 已对接={$importedAfter} 未对接={$newAfter}(期望 16/0)\n");
        $this->assertSame(16, $importedAfter, '导入后应全部标记已对接');
        $this->assertSame(0, $newAfter);
    }

    /** 核心修复验证:批量拉取只返回 2 个 code 时,兜底 getProduct 补齐其余 14 个 */
    public function test_import_fallback_to_getproduct_when_batch_missing(): void
    {
        // 16 个商品的完整列表(全部新货源)
        $codes = collect(range(1, 16))->map(fn ($i) => strtoupper(substr(md5("f{$i}"), 0, 16)))->all();

        // items 批量接口:只返回前 2 个(模拟上游响应不稳定/分页缺失)
        $partial = collect(array_slice($codes, 0, 2))->map(fn ($code) => [
            'code' => $code, 'name' => "商品{$code}", 'price' => '10.00',
        ])->all();
        $fullItems = collect($codes)->map(fn ($code) => [
            'code' => $code, 'name' => "商品{$code}", 'price' => '10.00',
        ])->all();

        Http::fake([
            // items 批量:只返回 2 个
            '*shared/commodity/items*' => Http::response([
                'code' => 200, 'msg' => 'success',
                'data' => [['id' => 1, 'name' => '分类1', 'children' => $partial]],
            ]),
            // item 单个:返回完整商品
            '*shared/commodity/item*' => function ($request) use ($fullItems) {
                $code = $request->data()['code'] ?? '';
                $match = collect($fullItems)->firstWhere('code', $code);
                if (! $match) {
                    return Http::response(['code' => 404, 'msg' => 'not found', 'data' => null]);
                }

                return Http::response(['code' => 200, 'msg' => 'success', 'data' => $match]);
            },
        ]);

        $source = SupplySource::create([
            'name' => 'S', 'driver' => 'acg_faka', 'base_url' => 'https://x.com',
            'credentials' => ['app_id' => '1', 'app_key' => 'k'], 'status' => 'active',
        ]);

        $resp = $this->withToken($this->adminToken())
            ->postJson("/api/admin/supply-sources/{$source->id}/products/import", [
                'codes' => $codes, 'pricing' => ['mode' => 'equal'],
            ]);

        $resp->assertOk();
        $body = $resp->json();
        fwrite(STDERR, "\n[fallback] imported={$body['imported']} skipped={$body['skipped']} msg={$body['message']}\n");
        // 批量只命中 2 个,兜底 getProduct 补齐 14 个 → 16 个全部导入
        $this->assertSame(16, $body['imported'], '批量缺失时应逐个 getProduct 兜底,保证全部导入');
        $this->assertSame(0, $body['skipped']);
    }

    /**
     * 客户真实 bug 复现:相似商品名但不同 code。
     * 上游"随机地区Gemini"(code=A)已导入;导入"美区Gemini"(code=B)时,
     * slug 兜底匹配曾把 B 误绑到 A 记录 → B 不入库、再次拉取仍显示新货源。
     * 修复后应:精确按 code 匹配,B 新建独立记录。
     */
    public function test_similar_names_do_not_cross_match_codes(): void
    {
        $source = SupplySource::create([
            'name' => 'S', 'driver' => 'acg_faka', 'base_url' => 'https://x.com',
            'credentials' => ['app_id' => '1', 'app_key' => 'k'], 'status' => 'active',
        ]);

        // 预置已导入:随机地区Gemini(code=A)
        Product::create([
            'merchant_id' => 1,
            'name' => '(随机22-24年账号)Gemini 3.1pro 12个月pixel成品号',
            'slug' => '22-24gemini-31pro-12pixel', // slug 与即将导入的"美区Gemini"相同
            'price' => 5200,
            'factory_price' => 0,
            'stock_type' => 'card',
            'status' => 1,
            'upstream_source_id' => $source->id,
            'upstream_product_code' => '46F96C0223CB875A',
        ]);

        // 模拟上游 items 只返回这一个商品(美区Gemini,code=B,slug 相同)
        Http::fake([
            '*shared/commodity/items*' => Http::response([
                'code' => 200, 'msg' => 'success',
                'data' => [[
                    'id' => 3, 'name' => 'gemini',
                    'children' => [[
                        'code' => '59B8227E22962629',
                        'name' => '(美区22-24年账号)Gemini 3.1pro 12个月pixel成品号',
                        'price' => '14.00',
                    ]],
                ]],
            ]),
        ]);

        $resp = $this->withToken($this->adminToken())
            ->postJson("/api/admin/supply-sources/{$source->id}/products/import", [
                'codes' => ['59B8227E22962629'],
                'pricing' => ['mode' => 'equal'],
            ]);

        $resp->assertOk();
        $body = $resp->json();
        fwrite(STDERR, "\n[cross-match] imported={$body['imported']} skipped={$body['skipped']} msg={$body['message']}\n");

        // 美区Gemini 应新建独立记录,code 精确 = 59B8..., 不与随机Gemini(code=46F9...) 串号
        $this->assertSame(1, $body['imported']);
        $created = Product::where('upstream_source_id', $source->id)
            ->where('upstream_product_code', '59B8227E22962629')->first();
        $this->assertNotNull($created, '美区Gemini 应新建独立记录(精确 code 匹配),而不是误绑到随机Gemini');
        $this->assertSame(2, Product::where('upstream_source_id', $source->id)->count(), '应存在 2 条独立记录');
        // 随机Gemini 的 code 保持原值未被覆盖
        $old = Product::where('upstream_source_id', $source->id)
            ->where('upstream_product_code', '46F96C0223CB875A')->first();
        $this->assertNotNull($old);
        $this->assertStringContainsString('随机', $old->name, '随机Gemini 记录不应被美区Gemini 覆盖');
    }
}
