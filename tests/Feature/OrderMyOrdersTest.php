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
 * 登录用户下单 → user_id 归属 → "我的订单"可见。
 *
 * 回归背景:POST /orders 不在 auth:sanctum 组内,控制器若用 $request->user()
 * 走默认 web guard(session) 无法解析 Bearer token,user_id 恒为 null,
 * 导致登录用户下单后"我的订单"(按 user_id 查)为空,而"订单查询"(按单号/联系方式)正常。
 */
class OrderMyOrdersTest extends TestCase
{
    use RefreshDatabase;

    private function seedBase(): void
    {
        Currency::firstOrCreate(['code' => 'CNY'], [
            'name' => '人民币', 'symbol' => '¥', 'symbol_position' => 'before',
            'decimal_places' => 2, 'exchange_rate' => '1', 'is_base' => true,
            'is_enabled' => true, 'sort' => 0,
        ]);
        // 默认开启下单验证码,测试环境关闭
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

    /** 登录用户带 Bearer token 下单:订单 user_id 必须归属该用户,且出现在"我的订单" */
    public function test_login_user_order_gets_user_id_and_appears_in_my_orders(): void
    {
        $this->seedBase();
        $p = $this->makeProduct('A', 10000);
        $user = User::factory()->create();
        $token = $user->createToken('test')->plainTextToken;

        $resp = $this->withHeaders(['Authorization' => 'Bearer '.$token])
            ->postJson('/api/orders', [
                'product_id' => $p->id,
                'qty' => 1,
                'contact' => 'buyer@test.com',
            ]);
        $resp->assertStatus(201);
        $orderNo = $resp->json('order_no');

        // 核心断言:user_id 写入(修复前此处为 null)
        $this->assertDatabaseHas('orders', [
            'order_no' => $orderNo,
            'user_id' => $user->id,
        ]);

        // "我的订单"能查到(修复前为空列表)
        $mine = $this->withHeaders(['Authorization' => 'Bearer '.$token])
            ->getJson('/api/orders/mine');
        $mine->assertOk();
        $mine->assertJsonCount(1);
        $this->assertSame($orderNo, $mine->json('0.order_no'));
    }

    /** 游客(无 token)下单:user_id 保持 null,不被任何"我的订单"收录 */
    public function test_guest_order_keeps_null_user_id(): void
    {
        $this->seedBase();
        $p = $this->makeProduct('A', 10000);

        $resp = $this->postJson('/api/orders', [
            'product_id' => $p->id,
            'qty' => 1,
            'contact' => 'guest@test.com',
            'password' => 'query-secret', // 游客下单强制设查询密码(order_query_password 默认开启)
        ]);
        $resp->assertStatus(201);
        $orderNo = $resp->json('order_no');

        $this->assertDatabaseHas('orders', [
            'order_no' => $orderNo,
            'user_id' => null,
        ]);
    }

    /** 购物车批量下单同样归属登录用户 */
    public function test_batch_order_gets_user_id_for_logged_in_user(): void
    {
        $this->seedBase();
        $p = $this->makeProduct('A', 10000);
        $user = User::factory()->create();
        $token = $user->createToken('test')->plainTextToken;

        $resp = $this->withHeaders(['Authorization' => 'Bearer '.$token])
            ->postJson('/api/orders/batch', [
                'items' => [['product_id' => $p->id, 'qty' => 1]],
                'contact' => 'buyer@test.com',
            ]);
        $resp->assertStatus(201);

        $this->assertDatabaseHas('orders', [
            'order_no' => $resp->json('orders.0.order_no'),
            'user_id' => $user->id,
        ]);
    }

    public function test_instructions_are_snapshotted_hidden_before_payment_and_returned_after_payment(): void
    {
        $this->seedBase();
        $p = $this->makeProduct('instructions', 10000);
        $p->update(['leave_message' => '<p onclick="alert(1)">先登录，再修改密码</p><script>alert(1)</script>']);
        $user = User::factory()->create();
        $token = $user->createToken('test')->plainTextToken;

        $created = $this->withHeaders(['Authorization' => 'Bearer '.$token])
            ->postJson('/api/orders', [
                'product_id' => $p->id,
                'qty' => 1,
                'contact' => 'instructions@test.com',
            ])->assertCreated();
        $orderNo = $created->json('order_no');

        $this->assertDatabaseHas('orders', ['order_no' => $orderNo]);
        $snapshot = (string) Order::where('order_no', $orderNo)->value('instructions_snapshot');
        $this->assertStringContainsString('先登录', $snapshot);
        $this->assertStringNotContainsString('onclick', $snapshot);
        $this->assertStringNotContainsString('<script', $snapshot);

        $mine = $this->withHeaders(['Authorization' => 'Bearer '.$token])->getJson('/api/orders/mine')->assertOk();
        $this->assertNull($mine->json('0.instructions'));

        // 商品后续修改不能影响订单快照。
        $p->update(['leave_message' => '<p>新教程</p>']);
        $this->postJson("/api/orders/{$orderNo}/mock-pay")->assertOk();

        $paid = $this->withHeaders(['Authorization' => 'Bearer '.$token])->getJson('/api/orders/mine')->assertOk();
        $this->assertStringContainsString('先登录', $paid->json('0.instructions'));
        $this->assertStringNotContainsString('新教程', $paid->json('0.instructions'));
    }
}
