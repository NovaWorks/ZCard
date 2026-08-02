<?php

namespace Tests\Feature;

use App\Models\SupplierAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SupplierAccountAdminTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // RefreshDatabase 清空权限表,需重建角色(P0 RBAC 守卫要求 super_admin/merchant)
        foreach (['super_admin', 'merchant', 'user'] as $role) {
            \Spatie\Permission\Models\Role::firstOrCreate(['name' => $role]);
        }
    }

    private function adminToken(): string
    {
        $user = User::factory()->create();
        $user->assignRole('super_admin');
        return $user->createToken('test')->plainTextToken;
    }

    public function test_create_account_returns_plaintext_secret_once(): void
    {
        config(['zcard.features.supply' => true]);
        $token = $this->adminToken();

        $resp = $this->withToken($token)->postJson('/api/admin/supplier-accounts', ['name' => '下游A']);
        $resp->assertStatus(201)->assertJsonPath('name', '下游A');
        $this->assertNotEmpty($resp->json('api_secret'));
        $this->assertNotEmpty($resp->json('api_key'));

        // 详情里 secret 应脱敏
        $show = $this->withToken($token)->getJson('/api/admin/supplier-accounts/' . $resp->json('id'));
        $show->assertOk();
        $this->assertStringStartsWith('••••', $show->json('api_secret'));
    }

    public function test_recharge_increases_balance_and_writes_ledger(): void
    {
        config(['zcard.features.supply' => true]);
        $token = $this->adminToken();
        $account = SupplierAccount::create(['name' => 'A', 'api_key' => 'k', 'api_secret' => 'enc', 'balance' => 0]);

        $resp = $this->withToken($token)->postJson("/api/admin/supplier-accounts/{$account->id}/recharge", ['amount' => 50000, 'remark' => '首充']);
        $resp->assertOk()->assertJsonPath('balance', 50000);
        $this->assertSame(50000, (int) $account->fresh()->balance);
        $this->assertDatabaseHas('supplier_ledger_entries', ['supplier_account_id' => $account->id, 'amount' => 50000]);
    }
}
