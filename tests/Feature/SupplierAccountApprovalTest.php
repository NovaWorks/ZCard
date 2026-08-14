<?php

namespace Tests\Feature;

use App\Models\SupplierAccount;
use App\Models\User;
use App\Supply\HmacSigner;
use App\Support\StorefrontConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * 供货账号审核制(安全审计 H-2):
 * - 自助开通默认待审核,SupplyAuth 拒绝;
 * - 后台开启 supply_auto_approve(注册即享供货价)时创建即通过;
 * - 管理员可在供货账号管理中审核通过/撤销;
 * - 管理员手动创建的账号默认审核通过;
 * - supply_supplier_enabled 关闭时供货 API 整体拒绝(开关此前无代码消费)。
 */
class SupplierAccountApprovalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Role::firstOrCreate(['name' => 'super_admin']);
        StorefrontConfig::setMany([
            'supply_enabled' => true,
            'supply_supplier_enabled' => true,
            'supply_auto_approve' => false,
            'supply_nonce_store' => 'cache',
        ]);
    }

    private function makeAdmin(): User
    {
        $user = User::factory()->create();
        $user->assignRole('super_admin');

        return $user;
    }

    /** 用账号密钥生成合法签名头(自助开通的账号 secret 为 Crypt 密文,需解密后签名) */
    private function signedPing(SupplierAccount $account): array
    {
        $secret = $account->getRawOriginal('api_secret');
        if (str_starts_with((string) $secret, 'eyJ')) {
            $secret = Crypt::decryptString($secret);
        }
        $ts = (string) time();
        $nonce = 'n'.uniqid();
        // postJson 空参加发 body 为 "[]"
        $ss = HmacSigner::buildSignString('POST', '/api/supply/ping', $ts, $nonce, md5('[]'));

        return [
            'X-Supply-Key' => $account->api_key,
            'X-Supply-Timestamp' => $ts,
            'X-Supply-Nonce' => $nonce,
            'X-Supply-Signature' => HmacSigner::sign($secret, $ss),
        ];
    }

    public function test_self_registered_account_starts_pending_and_supply_api_rejects(): void
    {
        $user = User::factory()->create();

        $resp = $this->actingAs($user, 'sanctum')->getJson('/api/supplier-account/me');
        $resp->assertOk()
            ->assertJsonPath('approved', false)
            ->assertJsonPath('is_new', true);
        // 待审核不下发明文密钥
        $this->assertSame('', $resp->json('api_secret'));
        $this->assertNotEmpty($resp->json('pending_notice'));

        $account = SupplierAccount::where('user_id', $user->id)->firstOrFail();
        $this->assertFalse((bool) $account->approved);

        // 合法签名也会被拒(审核门前置)
        $this->withHeaders($this->signedPing($account))
            ->postJson('/api/supply/ping')
            ->assertStatus(401)
            ->assertJson(['error_code' => 'unauthorized']);
    }

    public function test_auto_approve_setting_grants_instant_access(): void
    {
        StorefrontConfig::setMany(['supply_auto_approve' => true]);
        $user = User::factory()->create();

        $resp = $this->actingAs($user, 'sanctum')->getJson('/api/supplier-account/me');
        $resp->assertOk()->assertJsonPath('approved', true);
        // 自动通过时首次创建返回明文密钥
        $this->assertNotSame('', $resp->json('api_secret'));

        $account = SupplierAccount::where('user_id', $user->id)->firstOrFail();
        $this->withHeaders($this->signedPing($account))
            ->postJson('/api/supply/ping')
            ->assertOk();
    }

    public function test_admin_approval_unlocks_and_revocation_locks_supply_api(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user, 'sanctum')->getJson('/api/supplier-account/me')->assertOk();
        $account = SupplierAccount::where('user_id', $user->id)->firstOrFail();

        // 审核通过
        $admin = $this->makeAdmin();
        $this->actingAs($admin, 'sanctum')
            ->putJson("/api/admin/supplier-accounts/{$account->id}", ['approved' => true])
            ->assertOk()
            ->assertJsonPath('approved', true);

        $this->withHeaders($this->signedPing($account->fresh()))
            ->postJson('/api/supply/ping')
            ->assertOk();

        // 撤销审核 → 立即拒绝
        $this->actingAs($admin, 'sanctum')
            ->putJson("/api/admin/supplier-accounts/{$account->id}", ['approved' => false])
            ->assertOk();

        $this->withHeaders($this->signedPing($account->fresh()))
            ->postJson('/api/supply/ping')
            ->assertStatus(401)
            ->assertJson(['error_code' => 'unauthorized']);
    }

    public function test_admin_created_account_is_approved_by_default(): void
    {
        $admin = $this->makeAdmin();

        $resp = $this->actingAs($admin, 'sanctum')->postJson('/api/admin/supplier-accounts', [
            'name' => '下游B',
        ]);
        $resp->assertCreated();

        $account = SupplierAccount::findOrFail($resp->json('id'));
        $this->assertTrue((bool) $account->approved);
    }

    public function test_supplier_switch_off_rejects_even_approved_accounts(): void
    {
        $account = SupplierAccount::create([
            'name' => 'A', 'api_key' => 'ak_sw', 'api_secret' => 'sk',
            'status' => 'active', 'approved' => true,
        ]);

        StorefrontConfig::setMany(['supply_supplier_enabled' => false]);
        $this->withHeaders($this->signedPing($account))
            ->postJson('/api/supply/ping')
            ->assertStatus(401)
            ->assertJson(['error_code' => 'unauthorized']);
    }
}
