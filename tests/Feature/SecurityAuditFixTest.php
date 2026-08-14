<?php

namespace Tests\Feature;

use App\Models\Bill;
use App\Models\Card;
use App\Models\Merchant;
use App\Models\Product;
use App\Models\SubsiteLedgerEntry;
use App\Models\SupplierAccount;
use App\Models\User;
use App\Support\OrderService;
use App\Support\StorefrontConfig;
use App\Support\SubsiteWithdrawalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * 2026-08 安全审计修复回归:
 * H2 禁自调账/余额直写、M9 分站提现状态机、M10 供货余额负值防护、M11 mock-pay 门禁。
 */
class SecurityAuditFixTest extends TestCase
{
    use RefreshDatabase;

    private function makeAdmin(): User
    {
        Role::firstOrCreate(['name' => 'super_admin']);
        $user = User::factory()->create();
        $user->assignRole('super_admin');

        return $user;
    }

    public function test_admin_cannot_adjust_own_balance(): void
    {
        $admin = $this->makeAdmin();

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/admin/bills/adjust', [
                'user_id' => $admin->id,
                'amount' => 100,
                'type' => 1,
                'log' => 'self adjust',
            ])
            ->assertStatus(422);

        $this->assertSame(0, (int) $admin->fresh()->balance);
    }

    public function test_admin_can_adjust_other_users_balance_with_bill(): void
    {
        $admin = $this->makeAdmin();
        $other = User::factory()->create(['balance' => 0]);

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/admin/bills/adjust', [
                'user_id' => $other->id,
                'amount' => 50,
                'type' => 1,
                'log' => 'legit',
            ])
            ->assertStatus(201);

        $this->assertSame(5000, (int) $other->fresh()->balance);
        $this->assertDatabaseHas('bills', ['user_id' => $other->id, 'amount' => 5000, 'type' => 1]);
    }

    public function test_user_update_balance_goes_through_bill_service(): void
    {
        $admin = $this->makeAdmin();
        $target = User::factory()->create(['balance' => 2000]); // 20 元 = 2000 分

        $this->actingAs($admin, 'sanctum')
            ->putJson('/api/admin/users/'.$target->id, [
                'name' => '改名',
                'balance' => 0,
            ])
            ->assertOk();

        // 余额已变更,且产生支出流水(20 元),带操作人记录
        $this->assertSame(0, (int) $target->fresh()->balance);
        $this->assertDatabaseHas('bills', [
            'user_id' => $target->id,
            'amount' => 2000,
            'type' => Bill::TYPE_EXPENSE,
            'admin_id' => $admin->id,
        ]);
    }

    public function test_user_update_balance_income_creates_bill(): void
    {
        $admin = $this->makeAdmin();
        $target = User::factory()->create(['balance' => 0]);

        $this->actingAs($admin, 'sanctum')
            ->putJson('/api/admin/users/'.$target->id, ['balance' => 3000])
            ->assertOk();

        $this->assertSame(3000, (int) $target->fresh()->balance);
        $this->assertDatabaseHas('bills', [
            'user_id' => $target->id,
            'amount' => 3000,
            'type' => Bill::TYPE_INCOME,
            'admin_id' => $admin->id,
        ]);
    }

    public function test_admin_cannot_modify_own_balance_via_user_update(): void
    {
        $admin = $this->makeAdmin();
        // balance 已不在 fillable(安全加固:余额只走 BillService),测试用 forceFill 直改
        $admin->forceFill(['balance' => 1000])->save();

        $this->actingAs($admin, 'sanctum')
            ->putJson('/api/admin/users/'.$admin->id, ['balance' => 0])
            ->assertStatus(422);

        $this->assertSame(1000, (int) $admin->fresh()->balance);
        $this->assertDatabaseMissing('bills', ['user_id' => $admin->id]);
    }

    public function test_balance_change_fails_when_insufficient_ledger(): void
    {
        $admin = $this->makeAdmin();
        $target = User::factory()->create(['balance' => 100]);

        // 目标余额 min:0 校验挡负数;这里验证"把余额改小但差额超出现有余额"不可能发生——
        // min:0 下差额最多 = 当前余额,一定足够;此用例锁定负值输入被 422 拒绝。
        $this->actingAs($admin, 'sanctum')
            ->putJson('/api/admin/users/'.$target->id, ['balance' => -1])
            ->assertStatus(422);

        $this->assertSame(100, (int) $target->fresh()->balance);
    }

    public function test_subsite_withdrawal_cannot_be_reprocessed(): void
    {
        $owner = User::factory()->create();
        $merchant = Merchant::create([
            'user_id' => $owner->id, 'name' => 'S', 'slug' => 'sx', 'status' => 1,
            'commission_rate' => 0, 'settings' => ['is_subsite' => true],
        ]);
        SubsiteLedgerEntry::create([
            'merchant_id' => $merchant->id, 'type' => 'order_profit', 'amount' => 1000,
            'status' => 'available', 'idempotency_key' => 'k1', 'available_at' => now()->subDay(),
        ]);

        $w = SubsiteWithdrawalService::request($merchant->id, 500, 'alipay', 'a@b.c', 'T');
        SubsiteWithdrawalService::approve($w->id);

        // 已通过的不允许再次驳回(状态机)
        $this->expectException(\RuntimeException::class);
        SubsiteWithdrawalService::reject($w->id, 'double');
    }

    public function test_supplier_account_adjust_cannot_go_negative(): void
    {
        $admin = $this->makeAdmin();
        $user = User::factory()->create();
        $account = SupplierAccount::create([
            'user_id' => $user->id,
            'name' => '供货账号',
            'api_key' => 'key-'.bin2hex(random_bytes(4)),
            'api_secret' => bin2hex(random_bytes(16)),
            'balance' => 100,
            'status' => 1,
        ]);

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/admin/supplier-accounts/'.$account->id.'/adjust', [
                'amount' => -9999,
                'remark' => 'too much',
            ])
            ->assertStatus(400);

        $this->assertSame(100, (int) $account->fresh()->balance);
    }

    public function test_mock_pay_disabled_without_explicit_flag(): void
    {
        config(['zcard.allow_mock_payment' => false]);

        $merchant = Merchant::firstOrCreate(
            ['slug' => 'mp'],
            ['user_id' => User::factory()->create()->id, 'name' => 'M', 'status' => 1, 'commission_rate' => 0],
        );
        $product = Product::create([
            'merchant_id' => $merchant->id, 'name' => 'P', 'slug' => 'p-'.bin2hex(random_bytes(4)),
            'price' => 100, 'factory_price' => 0, 'status' => 1,
        ]);
        Card::create([
            'product_id' => $product->id, 'content' => 'CARD-1',
            'content_hash' => hash('sha256', 'CARD-1'), 'status' => 'unused',
        ]);

        $order = app(OrderService::class)->createOrder($product->id, null, 1, [
            'contact' => 'a@b.c',
        ]);

        $this->postJson("/api/orders/{$order->order_no}/mock-pay")->assertStatus(404);
        $this->assertSame('pending', $order->fresh()->status);
    }

    public function test_service_widget_allowed_hosts_config_defaults_exist(): void
    {
        $defaults = StorefrontConfig::defaults();
        $this->assertArrayHasKey('service_widget_allowed_hosts', $defaults);
        $this->assertContains('client.crisp.chat', $defaults['service_widget_allowed_hosts']);
    }
}
