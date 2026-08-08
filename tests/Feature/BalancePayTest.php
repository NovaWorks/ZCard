<?php

namespace Tests\Feature;

use App\Models\Card;
use App\Models\Category;
use App\Models\Currency;
use App\Models\Merchant;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Support\CardCipher;
use App\Support\StorefrontConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * 余额支付下单:扣款/发货/订单管理可见/越权与余额不足防护。
 */
class BalancePayTest extends TestCase
{
    use RefreshDatabase;

    private function seedBase(): void
    {
        Currency::firstOrCreate(['code' => 'CNY'], [
            'name' => '人民币', 'symbol' => '¥', 'symbol_position' => 'before',
            'decimal_places' => 2, 'exchange_rate' => '1', 'is_base' => true,
            'is_enabled' => true, 'sort' => 0,
        ]);
        StorefrontConfig::setMany(['trade_captcha' => false]);
        Cache::flush();
    }

    private function makeProduct(string $slug, int $price, int $stock = 2): Product
    {
        $u = User::factory()->create();
        $m = Merchant::firstOrCreate(['slug' => 'default'], ['user_id' => $u->id, 'name' => '主站', 'status' => 1, 'commission_rate' => 0]);
        $c = Category::create(['merchant_id' => $m->id, 'name' => 'C', 'slug' => 'c'.uniqid(), 'sort' => 0]);
        $p = Product::create([
            'merchant_id' => $m->id, 'category_id' => $c->id, 'name' => $slug,
            'slug' => $slug.uniqid(), 'price' => $price, 'factory_price' => (int) ($price * 0.6),
            'stock_type' => 'card', 'delivery_mode' => 'status', 'status' => true, 'sort' => 0,
        ]);
        for ($i = 0; $i < $stock; $i++) {
            Card::create(array_merge([
                'product_id' => $p->id,
                'status' => Card::STATUS_UNUSED,
            ], CardCipher::encryptWithHash($slug.'-'.$i.uniqid())));
        }

        return $p;
    }

    /** 登录用户下单(带 token),返回订单 */
    private function placeOrder(User $buyer, Product $p, int $qty = 1): Order
    {
        $resp = $this->withHeaders(['Authorization' => 'Bearer '.$buyer->createToken('test')->plainTextToken])
            ->postJson('/api/orders', ['product_id' => $p->id, 'qty' => $qty, 'contact' => 'buyer@test.com']);
        $resp->assertStatus(201);

        return Order::where('order_no', $resp->json('order_no'))->firstOrFail();
    }

    public function test_balance_pay_deducts_balance_and_marks_paid_and_delivers(): void
    {
        $this->seedBase();
        $p = $this->makeProduct('A', 3000);
        $buyer = User::factory()->create(['balance' => 10000]);
        $order = $this->placeOrder($buyer, $p);
        $token = $buyer->createToken('test')->plainTextToken;

        $resp = $this->withHeaders(['Authorization' => 'Bearer '.$token])
            ->postJson('/api/payments/balance', ['order_no' => $order->order_no]);
        $resp->assertOk();
        $resp->assertJson(['orders' => [['order_no' => $order->order_no, 'status' => 'paid', 'delivered' => true]]]);
        $this->assertSame('paid', $order->fresh()->status);
        $this->assertSame('balance', $order->fresh()->payment_channel);
        // 余额扣减 10000 - 3000
        $this->assertSame(7000, (int) $buyer->fresh()->balance);
        // 订单管理可见(admin orders 列表含该单)
        $this->assertDatabaseHas('orders', ['order_no' => $order->order_no, 'status' => 'paid', 'payment_channel' => 'balance']);
        // 已发货(OrderDelivery 生成)
        $this->assertSame(1, $order->fresh()->orderDeliveries()->count());
    }

    public function test_balance_pay_rejects_insufficient_balance(): void
    {
        $this->seedBase();
        $p = $this->makeProduct('A', 3000);
        $buyer = User::factory()->create(['balance' => 1000]);
        $order = $this->placeOrder($buyer, $p);

        $resp = $this->withHeaders(['Authorization' => 'Bearer '.$buyer->createToken('test')->plainTextToken])
            ->postJson('/api/payments/balance', ['order_no' => $order->order_no]);

        $resp->assertStatus(400);
        $this->assertSame('pending', $order->fresh()->status);
        $this->assertSame(1000, (int) $buyer->fresh()->balance);
    }

    public function test_balance_pay_rejects_other_users_order(): void
    {
        $this->seedBase();
        $p = $this->makeProduct('A', 3000);
        $buyer = User::factory()->create(['balance' => 10000]);
        $order = $this->placeOrder($buyer, $p);
        $attacker = User::factory()->create(['balance' => 10000]);

        // 测试环境 app 容器跨请求复用,Sanctum Guard 会缓存上一请求的用户,
        // 换用户请求前必须清除,否则 token 解析串号(生产 FPM 每请求独立进程无此问题)。
        $this->app['auth']->forgetGuards();
        $resp = $this->withHeaders(['Authorization' => 'Bearer '.$attacker->createToken('test')->plainTextToken])
            ->postJson('/api/payments/balance', ['order_no' => $order->order_no]);

        file_put_contents('/tmp/me-debug.log', print_r($this->withHeaders(['Authorization' => 'Bearer '.$attacker->createToken('test2')->plainTextToken])->getJson('/api/auth/me')->json(), true), FILE_APPEND);
        $resp->assertStatus(400);
        $this->assertSame('pending', $order->fresh()->status);
    }

    public function test_balance_pay_requires_login(): void
    {
        $resp = $this->postJson('/api/payments/balance', ['order_no' => 'RCH123']);
        $resp->assertStatus(401);
    }

    public function test_balance_batch_pay_deducts_total_once(): void
    {
        $this->seedBase();
        $p1 = $this->makeProduct('A', 3000);
        $p2 = $this->makeProduct('B', 2000);
        $buyer = User::factory()->create(['balance' => 10000]);
        $o1 = $this->placeOrder($buyer, $p1);
        $o2 = $this->placeOrder($buyer, $p2);

        $resp = $this->withHeaders(['Authorization' => 'Bearer '.$buyer->createToken('test')->plainTextToken])
            ->postJson('/api/payments/balance-batch', ['order_ids' => [$o1->id, $o2->id]]);

        $resp->assertOk();
        $this->assertSame(2, count($resp->json('orders')));
        $this->assertSame(5000, $resp->json('amount'));
        $this->assertSame(5000, (int) $buyer->fresh()->balance); // 10000 - 3000 - 2000
        $this->assertSame('paid', $o1->fresh()->status);
        $this->assertSame('paid', $o2->fresh()->status);
    }

    public function test_channels_includes_balance_for_logged_in_user(): void
    {
        $this->seedBase();
        $buyer = User::factory()->create(['balance' => 6666]);

        $resp = $this->withHeaders(['Authorization' => 'Bearer '.$buyer->createToken('test')->plainTextToken])
            ->getJson('/api/payments/channels');

        $resp->assertOk();
        $balance = collect($resp->json())->firstWhere('code', 'balance');
        $this->assertNotNull($balance);
        $this->assertSame(6666, $balance['balance']);
        $this->assertSame(0, $balance['id']);
    }

    public function test_channels_excludes_balance_for_guest(): void
    {
        $resp = $this->getJson('/api/payments/channels');
        $resp->assertOk();
        $this->assertNull(collect($resp->json())->firstWhere('code', 'balance'));
    }
}
