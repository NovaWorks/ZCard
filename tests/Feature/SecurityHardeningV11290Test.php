<?php

namespace Tests\Feature;

use App\Models\Bill;
use App\Models\Card;
use App\Models\Coupon;
use App\Models\Merchant;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentChannel;
use App\Models\Product;
use App\Models\ProductSku;
use App\Models\SupplierAccount;
use App\Models\SupplyOrder;
use App\Models\User;
use App\Payment\Drivers\OkPayDriver;
use App\Payment\Drivers\UsdtDriver;
use App\Supply\Exceptions\SupplyApiException;
use App\Supply\SupplyOrderService;
use App\Support\BillService;
use App\Support\CardService;
use App\Support\OrderService;
use App\Support\StorefrontConfig;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * v1.12.90 安全加固批次回归(审计 H/M/L 项):
 * H-1 下单服务端约束 / H-3 驱动自声明凭据键 / H-4 USDT 静态 key 移除 /
 * H-5 更新密码复验 / H-6 开户余额走流水 / H-7 会话 CSRF 守卫 /
 * H-8 慢通道关单宽限 / M-1 清理释放锁卡回滚券 / M-2 unlock 解绑·发货按锁定取卡 /
 * M-3 佣金不计累计充值 / M-5 供货取消真实退款
 */
class SecurityHardeningV11290Test extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Role::firstOrCreate(['name' => 'super_admin']);
        StorefrontConfig::setMany([
            'trade_captcha' => false,
            'order_close_minutes' => 15,
            'slow_channel_close_grace_minutes' => 60,
            'supply_enabled' => true,
            'supply_supplier_enabled' => true,
            'supply_nonce_store' => 'cache',
        ]);
    }

    private function makeMerchant(): Merchant
    {
        return Merchant::firstOrCreate(
            ['slug' => 'sec'],
            ['user_id' => User::factory()->create()->id, 'name' => 'SEC', 'status' => 1, 'commission_rate' => 0],
        );
    }

    private function makeProduct(array $overrides = []): Product
    {
        return Product::create(array_merge([
            'merchant_id' => $this->makeMerchant()->id,
            'name' => 'P', 'slug' => 'sec-'.uniqid(),
            'price' => 500, 'factory_price' => 300, 'stock_type' => 'card', 'status' => 1,
        ], $overrides));
    }

    private function addCard(Product $product, string $content = 'CARD-X'): Card
    {
        return Card::create([
            'product_id' => $product->id,
            'content' => $content, 'content_hash' => hash('sha256', $content),
            'status' => Card::STATUS_UNUSED,
        ]);
    }

    // ── H-1 下单服务端约束 ─────────────────────────────────────────────

    public function test_offshelf_and_hidden_products_cannot_be_ordered(): void
    {
        $off = $this->makeProduct(['status' => 0]);
        $this->expectException(ModelNotFoundException::class);
        app(OrderService::class)->createOrder($off->id, null, 1, ['contact' => 'x']);

        $hidden = $this->makeProduct(['hide' => true]);
        $this->expectException(ModelNotFoundException::class);
        app(OrderService::class)->createOrder($hidden->id, null, 1, ['contact' => 'x']);
    }

    public function test_member_only_product_rejects_guest(): void
    {
        $p = $this->makeProduct(['only_user' => true]);
        $this->addCard($p);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage(__('messages.order.member_only'));
        app(OrderService::class)->createOrder($p->id, null, 1, ['contact' => 'x']);
    }

    public function test_qty_bounds_and_purchase_limit_enforced_server_side(): void
    {
        $svc = app(OrderService::class);

        $min = $this->makeProduct(['min_order' => 2]);
        $this->addCard($min);
        $this->addCard($min);
        try {
            $svc->createOrder($min->id, null, 1, ['contact' => 'x']);
            $this->fail('低于最小起购未拦截');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString(__('messages.order.below_min_order', ['min' => 2]), $e->getMessage());
        }

        $max = $this->makeProduct(['max_order' => 1]);
        $this->addCard($max);
        $this->addCard($max);
        $this->expectException(\RuntimeException::class);
        $svc->createOrder($max->id, null, 2, ['contact' => 'x']);
    }

    public function test_purchase_limit_counts_pending_and_paid(): void
    {
        $user = User::factory()->create();
        $p = $this->makeProduct(['purchase_limit' => 3]);
        $this->addCard($p);
        $this->addCard($p);
        $this->addCard($p);

        // 已有一张 paid 订单(quantity 2),再购 2 张超限
        Order::create([
            'order_no' => 'ORDSEC1', 'merchant_id' => $p->merchant_id, 'product_id' => $p->id,
            'user_id' => $user->id, 'quantity' => 2, 'amount' => 1000, 'status' => 'paid',
            'delivery_status' => 'delivered', 'paid_at' => now(),
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage(__('messages.order.purchase_limit_exceeded', ['limit' => 3]));
        app(OrderService::class)->createOrder($p->id, null, 2, ['contact' => 'x', 'user_id' => $user->id]);
    }

    public function test_foreign_or_disabled_sku_rejected(): void
    {
        $p = $this->makeProduct();
        $this->addCard($p);
        $other = $this->makeProduct();
        $foreignSku = ProductSku::create(['product_id' => $other->id, 'name' => 'S', 'price' => 100, 'status' => 1]);
        $disabledSku = ProductSku::create(['product_id' => $p->id, 'name' => 'D', 'price' => 100, 'status' => 0]);

        $svc = app(OrderService::class);
        try {
            $svc->createOrder($p->id, $foreignSku->id, 1, ['contact' => 'x']);
            $this->fail('跨商品 SKU 未拦截');
        } catch (\RuntimeException $e) {
            $this->assertSame(__('messages.order.sku_unavailable'), $e->getMessage());
        }

        $this->expectException(\RuntimeException::class);
        $svc->createOrder($p->id, $disabledSku->id, 1, ['contact' => 'x']);
    }

    // ── H-8 慢通道关单宽限 ────────────────────────────────────────────

    public function test_close_expired_keeps_orders_with_active_slow_channel_payment(): void
    {
        $p = $this->makeProduct();
        $card = $this->addCard($p, 'SLOW-1');

        $order = app(OrderService::class)->createOrder($p->id, null, 1, ['contact' => 'x', 'create_ip' => '1.1.1.1']);
        // 把创建时间拨回 20 分钟前(超 15 分钟关单窗口),并补一条 5 分钟前的 USDT 未结流水
        Order::whereKey($order->id)->update(['created_at' => now()->subMinutes(20)]);
        Payment::create([
            'order_id' => $order->id, 'channel' => 'usdt', 'amount' => $order->amount,
            'status' => 'pending', 'charged_currency' => 'USD', 'charged_amount' => $order->amount,
            'created_at' => now()->subMinutes(5),
        ]);

        $closed = app(OrderService::class)->closeExpired();
        $this->assertSame(0, $closed);
        $this->assertSame('pending', $order->fresh()->status, '有未结慢通道支付的订单不应被关单');
        $this->assertSame(Card::STATUS_LOCKED, $card->fresh()->status, '锁定卡不应被释放');

        // 流水超过宽限期后可正常关单
        Payment::where('order_id', $order->id)->update(['created_at' => now()->subMinutes(120)]);
        $closed = app(OrderService::class)->closeExpired();
        $this->assertSame(1, $closed);
        $this->assertSame('closed', $order->fresh()->status);
    }

    // ── M-1 清理释放锁卡 + 回滚券 / M-2 unlock 解绑 ─────────────────

    public function test_admin_clear_releases_locked_cards_and_rolls_back_coupon(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('super_admin');

        $p = $this->makeProduct();
        $card = $this->addCard($p, 'CLEAR-1');
        $order = app(OrderService::class)->createOrder($p->id, null, 1, [
            'contact' => 'x', 'coupon_code' => null,
        ]);
        Order::whereKey($order->id)->update(['created_at' => now()->subMinutes(30)]);

        // 核销一张优惠券绑定该订单
        $coupon = Coupon::create([
            'code' => 'SEC'.bin2hex(random_bytes(4)), 'type' => 'fixed', 'value' => 100,
            'status' => Coupon::STATUS_USED, 'used_at' => now(), 'order_id' => $order->id,
        ]);

        $resp = $this->actingAs($admin, 'sanctum')->postJson('/api/admin/orders/clear');
        $resp->assertOk()->assertJsonPath('cleared', 1);

        $card->refresh();
        $this->assertSame(Card::STATUS_UNUSED, $card->status, '清理订单必须释放锁定卡');
        $this->assertNull($card->order_id);
        $this->assertSame(Coupon::STATUS_ACTIVE, $coupon->fresh()->status, '清理订单必须回滚已核销优惠券');
        $this->assertNull($coupon->fresh()->order_id);
    }

    public function test_unlock_clears_order_binding(): void
    {
        $p = $this->makeProduct();
        $card = $this->addCard($p, 'UNLOCK-1');
        $order = app(OrderService::class)->createOrder($p->id, null, 1, ['contact' => 'x']);

        $this->assertSame(Card::STATUS_LOCKED, $card->fresh()->status);

        app(CardService::class)->unlock([$card->id]);
        $card->refresh();
        $this->assertSame(Card::STATUS_UNUSED, $card->status);
        $this->assertNull($card->order_id, '解锁必须同时解除订单绑定(M-2)');
    }

    // ── H-6 开户余额走流水 / M-3 佣金不计累计充值 ────────────────────

    public function test_admin_store_balance_creates_bill_with_admin_id(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('super_admin');

        $resp = $this->actingAs($admin, 'sanctum')->postJson('/api/admin/users', [
            'username' => 'storebal', 'email' => 'storebal@test.local',
            'password' => 'Password123x', 'balance' => 5000,
        ]);
        $resp->assertCreated();

        $user = User::where('username', 'storebal')->firstOrFail();
        $this->assertSame(5000, (int) $user->balance);
        $bill = Bill::where('user_id', $user->id)->where('amount', 5000)->first();
        $this->assertNotNull($bill, '初始余额必须有流水');
        $this->assertSame($admin->id, $bill->admin_id);
    }

    public function test_commission_income_not_counted_as_recharge(): void
    {
        $user = User::factory()->create();
        $before = (int) $user->total_recharge;

        BillService::record($user->id, 1000, Bill::TYPE_INCOME, '分销佣金(测试)');
        $this->assertSame($before, (int) $user->fresh()->total_recharge, '佣金不得计入累计充值(M-3)');

        BillService::record($user->id, 2000, Bill::TYPE_INCOME, '在线充值(测试)', countAsRecharge: true);
        $this->assertSame($before + 2000, (int) $user->fresh()->total_recharge, '真实充值应计入累计充值');
    }

    // ── H-7 会话 CSRF 守卫 ────────────────────────────────────────────

    public function test_cross_origin_session_write_requires_csrf_token(): void
    {
        $user = User::factory()->create(['status' => 1]);
        $session = [
            Auth::guard('web')->getName() => $user->id,
            'password_hash_web' => $user->getAuthPassword(),
            '_token' => 'sec-test-token',
        ];

        // 跨站来源(Origin 非第一方)+ 会话认证的写请求 → 419
        $this->withSession($session)
            ->postJson('/api/auth/logout', [], ['Origin' => 'https://evil.example.com'])
            ->assertStatus(419);

        // 携带有效 CSRF token → 放行
        $this->withSession($session)
            ->postJson('/api/auth/logout', [], [
                'Origin' => 'https://evil.example.com',
                'X-CSRF-TOKEN' => 'sec-test-token',
            ])->assertOk();

        // Bearer 令牌认证不受 CSRF 约束
        $token = $user->createToken('t')->plainTextToken;
        $this->withToken($token)
            ->postJson('/api/auth/logout', [], ['Origin' => 'https://evil.example.com'])
            ->assertOk();
    }

    public function test_same_origin_session_write_skips_csrf_check(): void
    {
        $user = User::factory()->create(['status' => 1]);
        $session = [
            Auth::guard('web')->getName() => $user->id,
            'password_hash_web' => $user->getAuthPassword(),
            '_token' => 'sec-test-token',
        ];

        // 同源写请求(浏览器 Sec-Fetch-Site: same-origin):即使 Origin 不在 stateful
        // 域名内,也应与 PreventRequestForgery 同口径放行,不要求 CSRF token。
        // 复现:线上 APP_URL/SANCTUM_STATEFUL_DOMAINS 未配真实域名时,同源后台写请求
        // 被 VerifyCsrfForSessionAuth 误判为跨站 → 419 CSRF token mismatch。
        $this->withSession($session)
            ->postJson('/api/auth/logout', [], [
                'Origin' => 'https://kmigo.com',
                'Sec-Fetch-Site' => 'same-origin',
            ])
            ->assertOk();
    }

    // ── H-5 更新通道密码复验 ─────────────────────────────────────────

    public function test_update_run_requires_password_confirmation(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('super_admin');

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/admin/update/run')
            ->assertStatus(422)
            ->assertJsonPath('code', 'password_confirmation_required');

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/admin/update/rollback', ['password' => 'wrong-password'])
            ->assertStatus(422);
    }

    // ── H-3/H-4 支付驱动凭据门禁 ─────────────────────────────────────

    public function test_okpay_callback_passes_credential_gate_with_self_declared_keys(): void
    {
        $token = 'okp-token-1';
        $rate = '0.14';
        PaymentChannel::where('code', 'okpay')->delete();
        PaymentChannel::create([
            'code' => 'okpay', 'name' => 'OKPay', 'driver' => OkPayDriver::class,
            'enabled' => true,
            'config' => ['merchant_id' => 'M1', 'merchant_token' => $token, 'exchange_rate' => $rate],
        ]);

        $p = $this->makeProduct(['price' => 700]);
        $this->addCard($p, 'OKPAY-1');
        $order = app(OrderService::class)->createOrder($p->id, null, 1, ['contact' => 'x']);
        Payment::create([
            'order_id' => $order->id, 'channel' => 'okpay', 'amount' => $order->amount,
            'status' => 'pending', 'charged_currency' => 'USD', 'charged_amount' => $order->amount,
        ]);

        // 构造 OkPay 回调:status=success + data[status]=1 + 按 key 排序 md5(token) 大写签名
        // (与真实 form 提交一致传嵌套 data,驱动侧会展开为 data[xxx] 扁平键参与签名)
        $usdt = round($order->amount / ($rate * 100), 8);
        $data = ['amount' => (string) $usdt, 'order_id' => 'OK-EXT-1', 'status' => '1', 'unique_id' => $order->order_no];
        $flat = ['id' => 'M1', 'status' => 'success'];
        foreach ($data as $k => $v) {
            $flat["data[{$k}]"] = $v;
        }
        ksort($flat);
        $sign = strtoupper(md5(implode('&', array_map(fn ($k, $v) => $k.'='.$v, array_keys($flat), $flat)).'&token='.$token));
        $payload = ['id' => 'M1', 'status' => 'success', 'data' => $data];

        $resp = $this->post('/api/payments/callback/okpay', $payload + ['sign' => $sign]);
        $this->assertSame('success', $resp->getContent(), '凭据键自声明后回调应进入验签并被确认');
        $this->assertSame('paid', $order->fresh()->status);
    }

    public function test_usdt_static_api_key_fallback_removed(): void
    {
        PaymentChannel::where('code', 'usdt')->delete();
        PaymentChannel::create([
            'code' => 'usdt', 'name' => 'USDT', 'driver' => UsdtDriver::class,
            'enabled' => true,
            // 旧版静态 key 配置:只有 api_key 没有 secret_key
            'config' => ['api_key' => 'legacy-static-key', 'rate' => 7],
        ]);

        $p = $this->makeProduct();
        $this->addCard($p, 'USDT-1');
        $order = app(OrderService::class)->createOrder($p->id, null, 1, ['contact' => 'x']);
        Payment::create([
            'order_id' => $order->id, 'channel' => 'usdt', 'amount' => $order->amount,
            'status' => 'pending', 'charged_currency' => 'USD', 'charged_amount' => $order->amount,
        ]);

        // 旧版静态 key 方式的回调(头或 query 携带 api_key,无 HMAC 签名)必须被拒绝
        $resp = $this->post('/api/payments/callback/usdt', [
            'out_trade_no' => $order->order_no, 'status' => 'paid',
            'amount' => (string) round($order->amount / 700, 6), 'tx_id' => 'T1',
        ], ['X-API-Key' => 'legacy-static-key']);
        $this->assertSame('fail', $resp->getContent());
        $this->assertSame('pending', $order->fresh()->status);
    }

    // ── M-5 供货取消真实退款 ─────────────────────────────────────────

    public function test_supply_cancel_refunds_balance_and_closes_order(): void
    {
        $user = User::factory()->create();
        $account = SupplierAccount::create([
            'user_id' => $user->id, 'name' => 'A', 'api_key' => 'ak_m5', 'api_secret' => 'sk',
            'balance' => 0, 'status' => 'active', 'approved' => true,
        ]);
        $p = $this->makeProduct(['fulfillment_type' => 'manual']);
        $order = Order::create([
            'order_no' => 'ORGM5'.bin2hex(random_bytes(3)), 'merchant_id' => $p->merchant_id,
            'product_id' => $p->id, 'quantity' => 1, 'amount' => 300, 'status' => 'paid',
            'delivery_status' => 'pending', 'paid_at' => now(), 'source' => 'supply',
        ]);
        $supplyOrder = SupplyOrder::create([
            'supplier_account_id' => $account->id, 'order_id' => $order->id,
            'downstream_order_no' => 'DOWN-M5', 'fulfillment_mode' => 'async',
            'callback_url' => null, 'callback_status' => 'pending',
        ]);

        app(SupplyOrderService::class)->cancelOrder($account, $supplyOrder);

        $this->assertSame(300, (int) $account->fresh()->balance, '取消必须退款入账');
        $this->assertSame('closed', $order->fresh()->status);
        $this->assertDatabaseHas('supplier_ledger_entries', [
            'supplier_account_id' => $account->id, 'type' => 'refund', 'amount' => 300,
        ]);

        // 重复取消 → 409
        $this->expectException(SupplyApiException::class);
        app(SupplyOrderService::class)->cancelOrder($account, $supplyOrder->fresh());
    }
}
