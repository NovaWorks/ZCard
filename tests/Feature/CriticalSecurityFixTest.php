<?php

namespace Tests\Feature;

use App\Models\Card;
use App\Models\Merchant;
use App\Models\Product;
use App\Models\SupplierAccount;
use App\Models\User;
use App\Supply\Exceptions\SupplyApiException;
use App\Supply\SupplyOrderService;
use App\Support\OrderService;
use App\Support\StorefrontConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * 安全审计报告(C-1/H-1/H-2/H-4)修复回归:
 * - 种子占位管理员不可登录,CLI 安装必须重置其密码;
 * - 已安装(存在启用管理员)后禁止重跑安装向导;
 * - 分站 subdomain 绑定不再免验证,且禁止绑定主站域名;
 * - 供货价未配置(factory_price=0)时拒绝零价下单。
 */
class CriticalSecurityFixTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['super_admin', 'merchant', 'user'] as $r) {
            Role::firstOrCreate(['name' => $r]);
        }
    }

    public function test_seeded_placeholder_admin_is_disabled_and_unknown_password(): void
    {
        // RefreshDatabase 已执行全部迁移,占位管理员由迁移种子创建。
        $admin = User::withTrashed()->where('username', 'admin')->first();

        $this->assertNotNull($admin);
        // 占位账号被禁用且默认密码不可用
        $this->assertSame(0, (int) $admin->status);
        $this->assertFalse(Hash::check('admin123456', $admin->password));
    }

    public function test_cli_install_resets_placeholder_admin_password(): void
    {
        $this->artisan('zcard:install', [
            '--skip-db' => true,
            '--email' => 'boss@test.com',
            '--password' => 'NewStrongPass123',
        ])->assertSuccessful();

        $admin = User::withTrashed()->where('username', 'admin')->first();
        $this->assertSame(1, (int) $admin->status);
        $this->assertTrue(Hash::check('NewStrongPass123', $admin->password));
        $this->assertTrue($admin->hasRole('super_admin'));
    }

    public function test_install_run_rejected_when_active_admin_exists(): void
    {
        // 模拟"installed 锁文件丢失但站点已有启用管理员"的场景(H-2)。
        $admin = User::factory()->create(['status' => 1, 'password_changed_at' => now()]);
        $admin->assignRole('super_admin');

        $this->postJson('/api/install/run', [
            'db_host' => '127.0.0.1',
            'db_port' => 3306,
            'db_database' => 'x',
            'db_username' => 'x',
            'db_password' => 'x',
            'admin_email' => 'evil@evil.com',
            'admin_password' => 'EvilPassword123',
        ])->assertStatus(403);

        // 原管理员密码未被覆盖
        $this->assertTrue(Hash::check('password', $admin->fresh()->password));
    }

    public function test_supply_order_rejects_unconfigured_zero_price(): void
    {
        $merchant = Merchant::firstOrCreate(
            ['slug' => 'sp'],
            ['user_id' => User::factory()->create()->id, 'name' => 'S', 'status' => 1, 'commission_rate' => 0],
        );
        $product = Product::create([
            'merchant_id' => $merchant->id, 'name' => 'P', 'slug' => 'p-'.bin2hex(random_bytes(4)),
            'price' => 100, 'factory_price' => 0, 'status' => 1,
        ]);
        $account = SupplierAccount::create([
            'user_id' => User::factory()->create()->id,
            'name' => 'A',
            'api_key' => 'key-'.bin2hex(random_bytes(4)),
            'api_secret' => bin2hex(random_bytes(16)),
            'balance' => 10000,
            'status' => 1,
        ]);

        $this->expectException(SupplyApiException::class);
        app(SupplyOrderService::class)->createOrder($account, [
            'product_id' => $product->id,
            'quantity' => 1,
            'downstream_order_no' => 'DOWN-1',
        ], 'sync');
    }

    public function test_supply_order_price_above_zero_succeeds(): void
    {
        $merchant = Merchant::firstOrCreate(
            ['slug' => 'sp2'],
            ['user_id' => User::factory()->create()->id, 'name' => 'S2', 'status' => 1, 'commission_rate' => 0],
        );
        $product = Product::create([
            'merchant_id' => $merchant->id, 'name' => 'P2', 'slug' => 'p-'.bin2hex(random_bytes(4)),
            'price' => 500, 'factory_price' => 100, 'status' => 1,
        ]);
        Card::create([
            'product_id' => $product->id, 'content' => 'CARD-OK',
            'content_hash' => hash('sha256', 'CARD-OK'), 'status' => 'unused',
        ]);
        $account = SupplierAccount::create([
            'user_id' => User::factory()->create()->id,
            'name' => 'A2',
            'api_key' => 'key-'.bin2hex(random_bytes(4)),
            'api_secret' => bin2hex(random_bytes(16)),
            'balance' => 10000,
            'status' => 1,
        ]);

        $result = app(SupplyOrderService::class)->createOrder($account, [
            'product_id' => $product->id,
            'quantity' => 1,
            'downstream_order_no' => 'DOWN-2',
        ], 'sync');

        $this->assertSame(100, (int) $result['amount']);
    }

    public function test_order_query_locks_out_after_repeated_unauthorized_hits(): void
    {
        StorefrontConfig::setMany(['trade_captcha' => false]);
        $merchant = Merchant::firstOrCreate(
            ['slug' => 'q1'],
            ['user_id' => User::factory()->create()->id, 'name' => 'Q', 'status' => 1, 'commission_rate' => 0],
        );
        $product = Product::create([
            'merchant_id' => $merchant->id, 'name' => 'QP', 'slug' => 'qp-'.bin2hex(random_bytes(4)),
            'price' => 100, 'factory_price' => 0, 'status' => 1,
        ]);
        Card::create([
            'product_id' => $product->id, 'content' => 'CARD-QUERY',
            'content_hash' => hash('sha256', 'CARD-QUERY'), 'status' => 'unused',
        ]);
        // 创建带查询密码的订单(游客订单,攻击者不知道密码)
        $order = app(OrderService::class)->createOrder($product->id, null, 1, [
            'contact' => 'victim@test.com',
            'password' => 'correct-horse',
        ]);
        $orderNo = $order->order_no;

        for ($i = 0; $i < 5; $i++) {
            $resp = $this->postJson('/api/orders/query', [
                'keyword' => $orderNo,
                'password' => 'wrong-password',
            ]);
            $this->assertSame(200, $resp->getStatusCode());
            $this->assertSame([], $resp->json());
        }

        // 第 6 次尝试被锁定(429)
        $this->postJson('/api/orders/query', [
            'keyword' => $orderNo,
            'password' => 'wrong-password',
        ])->assertStatus(429);

        // 正确密码同样被锁(锁定是防爆破的硬门禁)
        $this->postJson('/api/orders/query', [
            'keyword' => $orderNo,
            'password' => 'correct-horse',
        ])->assertStatus(429);
    }

    public function test_login_locks_account_after_repeated_failures(): void
    {
        StorefrontConfig::setMany(['captcha_login' => false]);
        User::factory()->create(['email' => 'lockme@test.com', 'password' => 'right-password']);

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/auth/login', [
                'email' => 'lockme@test.com',
                'password' => 'wrong-password',
            ])->assertStatus(422);
        }

        // 账号级锁定已生效;第 6 次即使密码正确也拒绝(IP 限流可能先行触发 429)。
        $resp = $this->postJson('/api/auth/login', [
            'email' => 'lockme@test.com',
            'password' => 'right-password',
        ]);
        $this->assertContains($resp->getStatusCode(), [422, 429]);
        $this->assertTrue(cache()->has('login_lock:'.hash('sha256', 'lockme@test.com')));
    }
}
