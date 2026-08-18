<?php

namespace Tests\Feature;

use App\Models\Currency;
use App\Models\Merchant;
use App\Models\Order;
use App\Models\OrderDelivery;
use App\Models\PaymentChannel;
use App\Models\Product;
use App\Models\User;
use App\Payment\Drivers\EpayDriver;
use App\Support\FulfillmentService;
use App\Support\StorefrontConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class OrderAccessSecurityTest extends TestCase
{
    use RefreshDatabase;

    private Merchant $merchant;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        Currency::updateOrCreate(['code' => 'CNY'], [
            'code' => 'CNY', 'name' => '人民币', 'symbol' => '¥', 'symbol_position' => 'before',
            'decimal_places' => 2, 'exchange_rate' => '1', 'is_base' => true, 'is_enabled' => true,
        ]);
        $owner = User::factory()->create();
        $this->merchant = Merchant::create([
            'user_id' => $owner->id, 'name' => '主站', 'slug' => 'security-main', 'settings' => [],
        ]);
        $this->product = Product::create([
            'merchant_id' => $this->merchant->id,
            'name' => '安全测试商品',
            'slug' => 'security-product',
            'price' => 1000,
            'factory_price' => 500,
            'stock_type' => 'card',
            'fulfillment_type' => Product::FULFILLMENT_FIXED,
            'delivery_message' => '固定内容',
            'status' => true,
        ]);
        StorefrontConfig::setMany([
            'trade_captcha' => false,
            'order_query_password' => false,
            'mail_enabled' => false,
            'sms_enabled' => false,
        ]);
        Cache::flush();
    }

    private function paidOrder(
        string $orderNo,
        string $contact,
        string $accessToken,
        ?User $user = null,
        ?string $queryPasswordHash = null,
    ): Order {
        $extra = ['access_token_hash' => hash('sha256', $accessToken)];
        if ($queryPasswordHash !== null) {
            $extra['query_password'] = $queryPasswordHash;
        }

        $order = Order::create([
            'order_no' => $orderNo,
            'merchant_id' => $this->merchant->id,
            'user_id' => $user?->id,
            'product_id' => $this->product->id,
            'quantity' => 1,
            'amount' => 1000,
            'status' => 'paid',
            'delivery_status' => 'delivered',
            'fulfillment_type_snapshot' => Product::FULFILLMENT_AUTO_CARD,
            'contact' => $contact,
            'extra' => $extra,
            'paid_at' => now(),
        ]);
        OrderDelivery::create([
            'order_id' => $order->id,
            'product_id' => $this->product->id,
            'card_content' => 'SECRET-'.$orderNo,
            'delivered_mode' => 'status',
            'delivered_at' => now(),
        ]);

        return $order;
    }

    private function pendingOrder(string $orderNo, string $accessToken): Order
    {
        return Order::create([
            'order_no' => $orderNo,
            'merchant_id' => $this->merchant->id,
            'product_id' => $this->product->id,
            'quantity' => 1,
            'amount' => 1000,
            'status' => 'pending',
            'delivery_status' => 'pending',
            'fulfillment_type_snapshot' => Product::FULFILLMENT_FIXED,
            'contact' => 'payer@example.com',
            'extra' => ['access_token_hash' => hash('sha256', $accessToken)],
        ]);
    }

    public function test_order_number_or_contact_alone_cannot_read_paid_card_secrets(): void
    {
        $this->paidOrder('ORD-LEAK-1', 'victim@example.com', str_repeat('a', 64));

        $this->postJson('/api/orders/query', ['keyword' => 'ORD-LEAK-1'])
            ->assertOk()
            ->assertExactJson([]);
        $this->postJson('/api/orders/query', ['keyword' => 'victim@example.com'])
            ->assertOk()
            ->assertExactJson([]);
    }

    public function test_random_access_token_only_unlocks_its_own_order(): void
    {
        $token = str_repeat('b', 64);
        $this->paidOrder('ORD-TOKEN-1', 'shared@example.com', $token);
        $this->paidOrder('ORD-TOKEN-2', 'shared@example.com', str_repeat('c', 64));

        $response = $this->postJson('/api/orders/query', [
            'keyword' => 'shared@example.com',
            'access_token' => $token,
        ])->assertOk()->assertJsonCount(1);

        $this->assertSame('ORD-TOKEN-1', $response->json('0.order_no'));
        $this->assertSame(['SECRET-ORD-TOKEN-1'], $response->json('0.cards'));
    }

    public function test_query_password_and_authenticated_owner_remain_valid_access_paths(): void
    {
        $password = 'query-secret';
        $this->paidOrder(
            'ORD-PASSWORD',
            'password@example.com',
            str_repeat('d', 64),
            null,
            password_hash($password, PASSWORD_BCRYPT),
        );
        $user = User::factory()->create();
        $this->paidOrder('ORD-OWNER', 'owner@example.com', str_repeat('e', 64), $user);

        $this->postJson('/api/orders/query', [
            'keyword' => 'password@example.com',
            'password' => $password,
        ])->assertOk()->assertJsonPath('0.order_no', 'ORD-PASSWORD');

        $token = $user->createToken('order-access-test')->plainTextToken;
        $this->withToken($token)->postJson('/api/orders/query', ['keyword' => 'ORD-OWNER'])
            ->assertOk()
            ->assertJsonPath('0.cards.0', 'SECRET-ORD-OWNER');
    }

    public function test_guest_order_creation_returns_plain_token_but_only_stores_its_hash(): void
    {
        $response = $this->postJson('/api/orders', [
            'product_id' => $this->product->id,
            'qty' => 1,
            'contact' => 'new-guest@example.com',
        ])->assertCreated();

        $accessToken = (string) $response->json('access_token');
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $accessToken);

        $order = Order::where('order_no', $response->json('order_no'))->firstOrFail();
        $this->assertSame(hash('sha256', $accessToken), $order->extra['access_token_hash']);
        $this->assertStringNotContainsString($accessToken, json_encode($order->extra));
    }

    public function test_upstream_fulfillment_instructions_are_sanitized_before_storage(): void
    {
        $order = Order::create([
            'order_no' => 'ORD-XSS-UPSTREAM',
            'merchant_id' => $this->merchant->id,
            'product_id' => $this->product->id,
            'quantity' => 1,
            'amount' => 1000,
            'status' => 'paid',
            'delivery_status' => 'pending',
            'fulfillment_type_snapshot' => Product::FULFILLMENT_UPSTREAM,
        ]);

        app(FulfillmentService::class)->fulfill(
            $order,
            ['CARD-XSS-SAFE'],
            'upstream',
            '<p onclick="alert(1)">教程</p><script>alert(1)</script><a href="javascript:alert(1)">x</a>',
            notify: false,
        );

        $html = (string) $order->fresh()->instructions_snapshot;
        $this->assertStringContainsString('<p>教程</p>', $html);
        $this->assertStringNotContainsString('onclick', $html);
        $this->assertStringNotContainsString('<script', $html);
        $this->assertStringNotContainsString('javascript:', $html);
    }

    public function test_guest_cannot_initiate_single_or_batch_payment_for_unowned_orders(): void
    {
        $firstToken = str_repeat('1', 64);
        $secondToken = str_repeat('2', 64);
        $first = $this->pendingOrder('ORD-PAY-1', $firstToken);
        $second = $this->pendingOrder('ORD-PAY-2', $secondToken);
        $channel = PaymentChannel::create([
            'merchant_id' => $this->merchant->id,
            'name' => '安全测试易支付',
            'code' => 'security-epay',
            'driver' => EpayDriver::class,
            'config' => [
                'url' => 'https://pay.example.test/submit.php',
                'pid' => 'merchant-1',
                'key' => 'payment-secret',
                'type' => ['alipay'],
            ],
            'enabled' => true,
        ]);

        $this->postJson('/api/payments/create', [
            'order_no' => $first->order_no,
            'channel_id' => $channel->id,
        ])->assertNotFound();

        $this->postJson('/api/payments/create', [
            'order_no' => $first->order_no,
            'channel_id' => $channel->id,
            'access_token' => $firstToken,
        ])->assertOk()->assertJsonPath('type', 'redirect');

        $this->postJson('/api/payments/batch-create', [
            'order_ids' => [$first->id, $second->id],
            'channel_id' => $channel->id,
            'access_tokens' => [(string) $first->id => $firstToken],
        ])->assertNotFound();

        $this->postJson('/api/payments/batch-create', [
            'order_ids' => [$first->id, $second->id],
            'channel_id' => $channel->id,
            'access_tokens' => [
                (string) $first->id => $firstToken,
                (string) $second->id => $secondToken,
            ],
        ])->assertOk()->assertJsonPath('type', 'redirect');
    }

    /**
     * 回归:按联系方式查单时,前端无法预知命中哪几笔订单,只能带上本机持有的一批凭证。
     * 此前接口只接受单个 access_token,导致「用邮箱/手机号查单」必然返回空 —— 客户付款后看不到卡密。
     */
    public function test_contact_lookup_accepts_multiple_access_tokens(): void
    {
        $firstToken = str_repeat('7', 64);
        $secondToken = str_repeat('8', 64);
        $this->paidOrder('ORD-MULTI-1', 'buyer@example.com', $firstToken);
        $this->paidOrder('ORD-MULTI-2', 'buyer@example.com', $secondToken);
        $this->paidOrder('ORD-MULTI-OTHER', 'buyer@example.com', str_repeat('9', 64));

        $response = $this->postJson('/api/orders/query', [
            'keyword' => 'buyer@example.com',
            'access_tokens' => [$firstToken, $secondToken],
        ])->assertOk()->assertJsonCount(2);

        $this->assertEqualsCanonicalizing(
            ['ORD-MULTI-1', 'ORD-MULTI-2'],
            array_column($response->json(), 'order_no'),
        );
        // 未持有凭证的那笔仍然读不到,凭证不会互相串号
        $this->assertNotContains('ORD-MULTI-OTHER', array_column($response->json(), 'order_no'));
    }

    public function test_access_tokens_payload_is_bounded(): void
    {
        $this->postJson('/api/orders/query', [
            'keyword' => 'buyer@example.com',
            'access_tokens' => array_fill(0, 21, str_repeat('7', 64)),
        ])->assertStatus(422)->assertJsonValidationErrors('access_tokens');
    }

    /**
     * 游客订单的 access_token 只存在下单浏览器里,换设备即失效;
     * 开启查询密码时必须强制设置,否则订单离开原浏览器后无法再读取卡密。
     */
    public function test_guest_must_set_query_password_when_feature_enabled(): void
    {
        StorefrontConfig::setMany(['order_query_password' => true]);
        Cache::flush();

        $this->postJson('/api/orders', [
            'product_id' => $this->product->id,
            'qty' => 1,
            'contact' => 'no-password@example.com',
        ])->assertStatus(422)->assertJsonValidationErrors('password');

        $this->postJson('/api/orders', [
            'product_id' => $this->product->id,
            'qty' => 1,
            'contact' => 'with-password@example.com',
            'password' => 'query-secret',
        ])->assertCreated();

        // 登录用户凭账号即可读取订单,不受强制约束
        $user = User::factory()->create();
        $this->withToken($user->createToken('checkout')->plainTextToken)
            ->postJson('/api/orders', [
                'product_id' => $this->product->id,
                'qty' => 1,
                'contact' => 'member@example.com',
            ])->assertCreated();
    }
}
