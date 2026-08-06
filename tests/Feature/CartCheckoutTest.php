<?php

namespace Tests\Feature;

use App\Models\Card;
use App\Models\Category;
use App\Models\Coupon;
use App\Models\Currency;
use App\Models\Merchant;
use App\Models\Payment;
use App\Models\PaymentChannel;
use App\Models\Product;
use App\Models\User;
use App\Support\CardCipher;
use App\Support\OrderService;
use App\Support\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * 购物车收银台:批量下单 + 聚合支付。
 */
class CartCheckoutTest extends TestCase
{
    use RefreshDatabase;

    private function seedBase(): void
    {
        Currency::firstOrCreate(['code' => 'CNY'], [
            'name' => '人民币', 'symbol' => '¥', 'symbol_position' => 'before',
            'decimal_places' => 2, 'exchange_rate' => '1', 'is_base' => true,
            'is_enabled' => true, 'sort' => 0,
        ]);
        Cache::flush();
    }

    private function makeProduct(string $slug, int $price, int $stock = 3): Product
    {
        $u = User::factory()->create();
        $m = Merchant::firstOrCreate(['slug' => 'default'], ['user_id' => $u->id, 'name' => '主站', 'status' => 1, 'commission_rate' => 0]);
        $c = Category::create(['merchant_id' => $m->id, 'name' => 'C', 'slug' => 'c' . uniqid(), 'sort' => 0]);
        $p = Product::create([
            'merchant_id' => $m->id, 'category_id' => $c->id, 'name' => $slug,
            'slug' => $slug . uniqid(), 'price' => $price, 'factory_price' => (int) ($price * 0.6),
            'stock_type' => 'card', 'delivery_mode' => 'status', 'status' => true, 'sort' => 0,
        ]);
        for ($i = 0; $i < $stock; $i++) {
            Card::create(array_merge([
                'product_id' => $p->id,
                'status' => Card::STATUS_UNUSED,
            ], CardCipher::encryptWithHash($slug . '-' . $i . uniqid())));
        }
        return $p;
    }

    public function test_batch_create_creates_one_order_per_item(): void
    {
        $this->seedBase();
        $p1 = $this->makeProduct('A', 10000, 2);
        $p2 = $this->makeProduct('B', 5000, 2);

        $orders = app(OrderService::class)->batchCreate([
            ['product_id' => $p1->id, 'qty' => 1],
            ['product_id' => $p2->id, 'qty' => 2],
        ], ['contact' => 'a@b.c']);

        $this->assertCount(2, $orders);
        $this->assertSame(10000, (int) $orders[0]->amount);
        $this->assertSame(10000, (int) $orders[1]->amount); // 5000 × 2
        $this->assertSame('pending', $orders[0]->status);

        // 库存被锁定
        $this->assertSame(1, (int) $p1->cards()->where('status', Card::STATUS_LOCKED)->count());
        $this->assertSame(2, (int) $p2->cards()->where('status', Card::STATUS_LOCKED)->count());
    }

    public function test_batch_create_applies_coupon_to_first_matching_product(): void
    {
        $this->seedBase();
        $p1 = $this->makeProduct('A', 10000, 2);
        $p2 = $this->makeProduct('B', 5000, 2);

        $coupon = Coupon::create([
            'code' => 'CART10', 'type' => 'fixed', 'value' => 1000,
            'product_id' => $p2->id, 'min_amount' => 0,
            'status' => Coupon::STATUS_ACTIVE,
        ]);

        $orders = app(OrderService::class)->batchCreate(
            [
                ['product_id' => $p1->id, 'qty' => 1],
                ['product_id' => $p2->id, 'qty' => 1],
            ],
            ['contact' => 'a@b.c', 'coupon_code' => 'CART10'],
        );

        // 券只适用于 p2:第一个商品 p1 不带券(全价),p2 减 10 元
        $this->assertSame(10000, (int) $orders[0]->amount);
        $this->assertSame(4000, (int) $orders[1]->amount); // 5000 - 1000
        $this->assertSame('CART10', $orders[1]->coupon_code);
        $this->assertNull($orders[0]->coupon_code);
        // 券已核销
        $this->assertSame(Coupon::STATUS_USED, $coupon->fresh()->status);
    }

    public function test_batch_create_rolls_back_when_stock_insufficient(): void
    {
        $this->seedBase();
        $p1 = $this->makeProduct('A', 10000, 1);
        $p2 = $this->makeProduct('B', 5000, 1);

        $this->expectException(\App\Exceptions\InsufficientStockException::class);
        app(OrderService::class)->batchCreate([
            ['product_id' => $p1->id, 'qty' => 1],
            ['product_id' => $p2->id, 'qty' => 5], // 库存不足
        ], ['contact' => 'a@b.c']);

        // 整体回滚:p1 的订单也不应存在
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_batch_payment_creates_aggregate_payment(): void
    {
        $this->seedBase();
        $p1 = $this->makeProduct('A', 10000, 1);
        $p2 = $this->makeProduct('B', 5000, 1);

        $orders = app(OrderService::class)->batchCreate(
            [
                ['product_id' => $p1->id, 'qty' => 1],
                ['product_id' => $p2->id, 'qty' => 1],
            ],
            ['contact' => 'a@b.c'],
        );

        // 启用一个支付通道(预置 seed 已有 epay,更新配置即可)
        $channel = PaymentChannel::updateOrCreate(
            ['merchant_id' => 1, 'code' => 'epay'],
            [
                'name' => '易支付',
                'driver' => \App\Payment\Drivers\EpayDriver::class,
                'config' => ['gateway_url' => 'https://pay.test', 'pid' => '1', 'key' => 'abc', 'sign_type' => 'md5'],
                'enabled' => true, 'sort' => 0,
            ],
        );

        $result = app(PaymentService::class)->createBatchPayment(
            $orders->pluck('id')->all(),
            $channel->id,
        );

        $this->assertNotEmpty($result['redirect_url'] ?? $result['form_html'] ?? '');
        $payment = Payment::where('order_ids', '!=', null)->orderByDesc('id')->first();
        $this->assertNotNull($payment);
        $this->assertCount(2, $payment->order_ids);
        $this->assertSame(15000, (int) $payment->amount);
        $this->assertSame($orders[0]->id, $payment->order_id); // 主订单
        $this->assertSame('pending', $payment->status);
    }

    public function test_batch_payment_rejects_already_paid_order(): void
    {
        $this->seedBase();
        $p1 = $this->makeProduct('A', 10000, 1);
        $p2 = $this->makeProduct('B', 5000, 1);

        $orders = app(OrderService::class)->batchCreate(
            [
                ['product_id' => $p1->id, 'qty' => 1],
                ['product_id' => $p2->id, 'qty' => 1],
            ],
            ['contact' => 'a@b.c'],
        );
        $orders[1]->update(['status' => 'closed']);

        $channel = PaymentChannel::updateOrCreate(
            ['merchant_id' => 1, 'code' => 'epay'],
            [
                'name' => '易支付',
                'driver' => \App\Payment\Drivers\EpayDriver::class,
                'config' => ['gateway_url' => 'https://pay.test', 'pid' => '1', 'key' => 'abc', 'sign_type' => 'md5'],
                'enabled' => true, 'sort' => 0,
            ],
        );

        $this->expectException(\RuntimeException::class);
        app(PaymentService::class)->createBatchPayment($orders->pluck('id')->all(), $channel->id);
    }
}
