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
        $this->assertCount(3, $data['premium_numbers']);
        // 按价格升序
        $this->assertSame('13800138000', $data['premium_numbers'][0]['number']);
        $this->assertSame(3000, $data['premium_numbers'][0]['price']);
        $this->assertSame(5000, $data['premium_numbers'][1]['price']);
        $this->assertSame(8000, $data['premium_numbers'][2]['price']);
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
