<?php

namespace Tests\Feature;

use App\Models\SupplySource;
use App\Models\User;
use App\Support\StorefrontConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class SupplySourceAdminTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // RefreshDatabase 清空权限表,需重建角色(P0 RBAC 守卫要求 super_admin/merchant)
        foreach (['super_admin', 'merchant', 'user'] as $role) {
            Role::firstOrCreate(['name' => $role]);
        }
    }

    private function adminToken(): string
    {
        $user = User::factory()->create();
        $user->assignRole('super_admin');

        return $user->createToken('test')->plainTextToken;
    }

    public function test_drivers_endpoint_returns_schema(): void
    {
        StorefrontConfig::setMany(['supply_enabled' => true]);
        $resp = $this->withToken($this->adminToken())->getJson('/api/admin/supply-sources/drivers');
        $resp->assertOk()->assertJsonCount(3, 'drivers');
        $this->assertNotNull($resp->json('drivers.0.config_schema.base_url'));
    }

    public function test_create_source_encrypts_credentials(): void
    {
        StorefrontConfig::setMany(['supply_enabled' => true]);
        $resp = $this->withToken($this->adminToken())->postJson('/api/admin/supply-sources', [
            'name' => '主站', 'driver' => 'dujiao_next', 'base_url' => 'https://up.example.com',
            'credentials' => ['base_url' => 'https://up.example.com', 'api_key' => 'ak', 'api_secret' => 'sk_secret'],
        ]);
        $resp->assertStatus(201);
        // 返回脱敏
        $this->assertStringStartsWith('••••', $resp->json('credentials.api_secret'));
        // DB 存密文(不含明文 sk_secret)
        $raw = DB::table('supply_sources')->where('id', $resp->json('id'))->value('credentials');
        $this->assertStringNotContainsString('sk_secret', $raw);
    }

    public function test_update_credentials_merges_keeping_secrets(): void
    {
        StorefrontConfig::setMany(['supply_enabled' => true]);
        $source = SupplySource::create([
            'name' => 'S', 'driver' => 'dujiao_next', 'base_url' => 'https://x.com',
            'credentials' => ['api_key' => 'ak', 'api_secret' => 'oldsecret'], 'status' => 'active',
        ]);
        // 更新时 api_secret 留空=不修改
        $resp = $this->withToken($this->adminToken())->putJson("/api/admin/supply-sources/{$source->id}", [
            'credentials' => ['api_key' => 'newkey', 'api_secret' => null],
        ]);
        $resp->assertOk();
        $fresh = $source->fresh();
        $this->assertSame('newkey', $fresh->credentials['api_key']);
        $this->assertSame('oldsecret', $fresh->credentials['api_secret']); // 保留旧值
    }

    public function test_preview_with_nonexistent_source_returns_friendly_404(): void
    {
        StorefrontConfig::setMany(['supply_enabled' => true]);
        // 建一个货源,让"可用 id"列表非空
        $source = SupplySource::create([
            'name' => 'S', 'driver' => 'dujiao_next', 'base_url' => 'https://x.com',
            'credentials' => ['api_key' => 'ak'], 'status' => 'active',
        ]);
        $missingId = $source->id + 999;

        // 隐式路由绑定查不到记录 → 不再返回裸 "Not Found",而是带可用 id 的自诊断错误
        $resp = $this->withToken($this->adminToken())
            ->getJson("/api/admin/supply-sources/{$missingId}/products/preview");

        $resp->assertStatus(404)
            ->assertJsonPath('ok', false)
            ->assertJsonPath('error', "货源不存在: id={$missingId}。可用货源 id: {$source->id}。请先 GET /api/admin/supply-sources 获取真实 id。");
    }
}
