<?php

namespace Tests\Feature;

use App\Models\Card;
use App\Models\CardImport;
use App\Models\Merchant;
use App\Models\Product;
use App\Models\User;
use App\Support\CardImportService;
use App\Support\OrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PremiumPickTest extends TestCase
{
    use RefreshDatabase;

    private function makeProduct(array $overrides = []): Product
    {
        $user = User::factory()->create();
        Merchant::firstOrCreate(
            ['id' => 1],
            ['name' => '主站', 'slug' => 'main-'.uniqid(), 'user_id' => $user->id, 'settings' => []],
        );

        return Product::create(array_merge([
            'merchant_id' => 1,
            'name' => '靓号商品',
            'slug' => 'premium-'.uniqid(),
            'price' => 1000,
            'factory_price' => 0,
            'stock_type' => 'card',
            'status' => 1,
            'pick_type' => 'premium',
        ], $overrides));
    }

    private function importPremiumCards(int $productId): CardImport
    {
        $raw = "18888888888---50---含2月接码\n"
            ."19999999999---80---含3月接码\n"
            ."13800138000---30---普通\n";

        return app(CardImportService::class)->import(
            $productId,
            User::factory()->create()->id,
            $raw,
            ['card_type' => '靓号自选', 'source' => 'test'],
        );
    }

    public function test_premium_import_parses_price_from_second_segment(): void
    {
        $product = $this->makeProduct();
        $import = $this->importPremiumCards($product->id);

        $this->assertSame('completed', $import->fresh()->status);
        $this->assertSame(3, $import->fresh()->success_count);

        $cards = Card::where('product_id', $product->id)->get();
        $this->assertCount(3, $cards);
        // 每条价格 = 第二段金额(元转分)
        $prices = $cards->pluck('price')->sort()->values()->all();
        $this->assertSame([3000, 5000, 8000], $prices);
        // 整行明文保留
        $first = $cards->firstWhere('price', 5000);
        $this->assertStringContainsString('18888888888---50---含2月接码', $first->plainContent());
    }

    public function test_premium_numbers_exposed_in_product_show(): void
    {
        $product = $this->makeProduct();
        $this->importPremiumCards($product->id);

        $resp = $this->getJson("/api/products/{$product->slug}?display_currency=CNY");
        $resp->assertOk();
        $data = $resp->json();
        $this->assertSame('premium', $data['pick_type']);
        // 分页结构
        $this->assertSame(3, $data['premium_numbers']['total']);
        $this->assertSame(3, count($data['premium_numbers']['list']));
        $this->assertFalse($data['premium_numbers']['has_more']);
        // 最低价
        $this->assertSame(3000, $data['premium_numbers']['min_price']);
        // 按价格升序
        $list = $data['premium_numbers']['list'];
        $this->assertSame('13800138000', $list[0]['number']);
        $this->assertSame(3000, $list[0]['price']);
        $this->assertSame(5000, $list[1]['price']);
        $this->assertSame(8000, $list[2]['price']);
    }

    public function test_premium_numbers_support_keyword_search_and_pagination(): void
    {
        $product = $this->makeProduct();
        $this->importPremiumCards($product->id);

        // keyword 搜索
        $resp = $this->getJson("/api/products/{$product->slug}?display_currency=CNY&keyword=188");
        $resp->assertOk();
        $pn = $resp->json('premium_numbers');
        $this->assertSame(1, $pn['total']);
        $this->assertSame('18888888888', $pn['list'][0]['number']);

        // 分页(每页 2 条 → 第 2 页剩 1 条)
        $page1 = $this->getJson("/api/products/{$product->slug}?display_currency=CNY&per_page=2&page=1");
        $p1 = $page1->json('premium_numbers');
        $this->assertSame(2, count($p1['list']));
        $this->assertTrue($p1['has_more']);
        $page2 = $this->getJson("/api/products/{$product->slug}?display_currency=CNY&per_page=2&page=2");
        $p2 = $page2->json('premium_numbers');
        $this->assertSame(1, count($p2['list']));
        $this->assertFalse($p2['has_more']);
        $this->assertSame(8000, $p2['list'][0]['price']);
    }

    public function test_premium_numbers_can_pinpoint_by_card_id(): void
    {
        $product = $this->makeProduct();
        $this->importPremiumCards($product->id);
        $target = Card::where('product_id', $product->id)->where('price', 8000)->first();

        $resp = $this->getJson("/api/products/{$product->slug}?display_currency=CNY&card_id={$target->id}");
        $resp->assertOk();
        $pn = $resp->json('premium_numbers');
        $this->assertSame(1, $pn['total']);
        $this->assertSame($target->id, $pn['list'][0]['card_id']);
    }

    public function test_premium_import_rejects_duplicate_number_across_products(): void
    {
        $productA = $this->makeProduct();
        $productB = $this->makeProduct(['name' => '靓号商品B']);
        $this->importPremiumCards($productA->id);

        // 商品 B 导入:18888888888 与商品 A 重复(应失败),13777777777 全新(应成功)
        $raw = "18888888888---99---另一个商品\n13777777777---120---含5月接码\n";
        $import = app(CardImportService::class)->import(
            $productB->id,
            User::factory()->create()->id,
            $raw,
            ['card_type' => '靓号自选', 'source' => 'test'],
        );

        $this->assertSame('completed', $import->fresh()->status);
        $this->assertSame(1, $import->fresh()->success_count);
        $this->assertSame(1, $import->fresh()->failed_count);
        $errorLog = $import->fresh()->error_log;
        $this->assertIsArray($errorLog);
        $this->assertStringContainsString('18888888888', json_encode($errorLog));
    }

    public function test_premium_import_rejects_duplicate_number_in_same_batch(): void
    {
        $product = $this->makeProduct();
        $raw = "18888888888---50---含2月接码\n18888888888---99---同号不同价\n";
        $import = app(CardImportService::class)->import(
            $product->id,
            User::factory()->create()->id,
            $raw,
            ['card_type' => '靓号自选', 'source' => 'test'],
        );

        $this->assertSame(1, $import->fresh()->success_count);
        $this->assertSame(1, $import->fresh()->failed_count);
    }

    public function test_premium_import_sets_number_hash(): void
    {
        $product = $this->makeProduct();
        $this->importPremiumCards($product->id);

        $cards = Card::where('product_id', $product->id)->get();
        foreach ($cards as $card) {
            $this->assertNotNull($card->number_hash);
            $number = trim(explode('---', $card->plainContent())[0]);
            $this->assertSame(hash('sha256', $number), $card->number_hash);
        }
    }

    public function test_premium_order_locks_selected_card_with_card_price(): void
    {
        $product = $this->makeProduct();
        $this->importPremiumCards($product->id);
        $target = Card::where('product_id', $product->id)->where('price', 8000)->first();

        $order = app(OrderService::class)->createOrder(
            $product->id,
            null,
            1,
            ['contact' => 'buyer@test.com', 'card_id' => $target->id],
        );

        // 金额 = 所选靓号价格 8000 分
        $this->assertSame(8000, (int) $order->amount);
        // 该卡被锁定并绑定订单
        $this->assertSame('locked', $target->fresh()->status);
        $this->assertSame($order->id, $target->fresh()->order_id);
        // 其余卡未受影响
        $this->assertSame(2, Card::where('product_id', $product->id)->where('status', 'unused')->count());
    }

    public function test_premium_order_requires_card_id(): void
    {
        $product = $this->makeProduct();
        $this->importPremiumCards($product->id);

        $this->expectException(\RuntimeException::class);
        app(OrderService::class)->createOrder($product->id, null, 1, ['contact' => 'x@test.com']);
    }

    public function test_premium_order_rejects_already_locked_card(): void
    {
        $product = $this->makeProduct();
        $this->importPremiumCards($product->id);
        $target = Card::where('product_id', $product->id)->first();
        $target->update(['status' => 'locked', 'order_id' => 999]);

        $this->expectException(\RuntimeException::class);
        app(OrderService::class)->createOrder(
            $product->id,
            null,
            1,
            ['contact' => 'x@test.com', 'card_id' => $target->id],
        );
    }
}
