<?php

namespace Tests\Feature;

use App\Models\Card;
use App\Models\Category;
use App\Models\Currency;
use App\Models\Merchant;
use App\Models\Product;
use App\Models\User;
use App\Support\CaptchaService;
use App\Support\CardCipher;
use App\Support\StorefrontConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Mews\Captcha\Facades\Captcha;
use Tests\TestCase;

class TradeCaptchaTest extends TestCase
{
    use RefreshDatabase;

    public function test_trade_captcha_endpoint_returns_keyed_inline_image(): void
    {
        Captcha::shouldReceive('create')
            ->once()
            ->with('trade', true)
            ->andReturn([
                'key' => 'captcha-key',
                'img' => 'data:image/jpeg;base64,ZmFrZQ==',
                'sensitive' => false,
            ]);

        $this->getJson('/api/captcha/trade')
            ->assertOk()
            ->assertExactJson([
                'key' => 'captcha-key',
                'src' => 'data:image/jpeg;base64,ZmFrZQ==',
            ]);
    }

    public function test_keyed_trade_captcha_does_not_depend_on_session(): void
    {
        Captcha::shouldReceive('check_api')
            ->once()
            ->with('1234', 'captcha-key', 'trade')
            ->andReturn(true);

        $this->assertTrue(CaptchaService::verify('trade', '1234', 'captcha-key'));
    }

    public function test_order_creation_accepts_keyed_trade_captcha(): void
    {
        $this->seedBase();
        $product = $this->makeProduct();

        Captcha::shouldReceive('check_api')
            ->once()
            ->with('1234', 'captcha-key', 'trade')
            ->andReturn(true);

        $this->postJson('/api/orders', [
            'product_id' => $product->id,
            'qty' => 1,
            'contact' => 'captcha@example.com',
            'captcha' => '1234',
            'captcha_key' => 'captcha-key',
        ])->assertCreated();
    }

    private function seedBase(): void
    {
        Currency::firstOrCreate(['code' => 'CNY'], [
            'name' => '人民币',
            'symbol' => '¥',
            'symbol_position' => 'before',
            'decimal_places' => 2,
            'exchange_rate' => '1',
            'is_base' => true,
            'is_enabled' => true,
            'sort' => 0,
        ]);
        StorefrontConfig::setMany(['trade_captcha' => true]);
        Cache::flush();
    }

    private function makeProduct(): Product
    {
        $user = User::factory()->create();
        $merchant = Merchant::firstOrCreate(['slug' => 'default'], [
            'user_id' => $user->id,
            'name' => '主站',
            'status' => 1,
            'commission_rate' => 0,
        ]);
        $category = Category::create([
            'merchant_id' => $merchant->id,
            'name' => '验证码测试',
            'slug' => 'captcha-test-'.uniqid(),
            'sort' => 0,
        ]);
        $product = Product::create([
            'merchant_id' => $merchant->id,
            'category_id' => $category->id,
            'name' => '验证码商品',
            'slug' => 'captcha-product-'.uniqid(),
            'price' => 1000,
            'factory_price' => 500,
            'stock_type' => 'card',
            'delivery_mode' => 'status',
            'status' => true,
            'sort' => 0,
        ]);
        Card::create(array_merge([
            'product_id' => $product->id,
            'status' => Card::STATUS_UNUSED,
        ], CardCipher::encryptWithHash('CAPTCHA-CARD-001')));

        return $product;
    }
}
