<?php

namespace Tests\Feature;

use App\Models\SupplierAccount;
use App\Supply\HmacSigner;
use App\Support\StorefrontConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 供货 API 动态限流中间件测试
 * 验证 rate_limit 从 StorefrontConfig 动态读取(改了立即生效)。
 */
class SupplyRateLimitTest extends TestCase
{
    use RefreshDatabase;

    /**
     * ping 用 postJson 不带数据时实际发送 body = '[]',
     * 签名必须基于真实 body 的 md5(否则 invalid_signature)。
     */
    private function signedPingHeaders(SupplierAccount $account): array
    {
        $ts = (string) time();
        $nonce = 'n'.uniqid();
        // postJson('/api/supply/ping') 不带数据 → body = '[]'
        $ss = HmacSigner::buildSignString('POST', '/api/supply/ping', $ts, $nonce, md5('[]'));

        return [
            'X-Supply-Key' => $account->api_key,
            'X-Supply-Timestamp' => $ts,
            'X-Supply-Nonce' => $nonce,
            'X-Supply-Signature' => HmacSigner::sign($account->getRawOriginal('api_secret'), $ss),
        ];
    }

    public function test_requests_under_limit_pass(): void
    {
        StorefrontConfig::setMany(['supply_enabled' => true, 'supply_nonce_store' => 'cache', 'supply_rate_limit' => 3]);

        $account = SupplierAccount::create([
            'name' => 'A', 'api_key' => 'ak_rate1', 'api_secret' => 'sk', 'balance' => 10000, 'status' => 'active', 'approved' => true,
        ]);

        // 3 次以内应放行(200/201),ping 返回 200
        for ($i = 0; $i < 3; $i++) {
            $resp = $this->withHeaders($this->signedPingHeaders($account))->postJson('/api/supply/ping');
            $resp->assertOk();
        }
    }

    public function test_request_over_limit_returns_429(): void
    {
        StorefrontConfig::setMany(['supply_enabled' => true, 'supply_nonce_store' => 'cache', 'supply_rate_limit' => 2]);

        $account = SupplierAccount::create([
            'name' => 'A', 'api_key' => 'ak_rate2', 'api_secret' => 'sk', 'balance' => 10000, 'status' => 'active', 'approved' => true,
        ]);

        // 前 2 次放行
        $this->withHeaders($this->signedPingHeaders($account))->postJson('/api/supply/ping')->assertOk();
        $this->withHeaders($this->signedPingHeaders($account))->postJson('/api/supply/ping')->assertOk();
        // 第 3 次超限 → 429
        $resp = $this->withHeaders($this->signedPingHeaders($account))->postJson('/api/supply/ping');
        $resp->assertStatus(429)->assertJsonPath('error_code', 'too_many_requests');
    }

    public function test_rate_limit_change_takes_effect_immediately(): void
    {
        StorefrontConfig::setMany(['supply_enabled' => true, 'supply_nonce_store' => 'cache', 'supply_rate_limit' => 5]);

        $account = SupplierAccount::create([
            'name' => 'A', 'api_key' => 'ak_rate3', 'api_secret' => 'sk', 'balance' => 10000, 'status' => 'active', 'approved' => true,
        ]);

        // 用满 5 次
        for ($i = 0; $i < 5; $i++) {
            $this->withHeaders($this->signedPingHeaders($account))->postJson('/api/supply/ping')->assertOk();
        }
        // 改成 10,应继续放行(新窗口)
        StorefrontConfig::setMany(['supply_rate_limit' => 10]);
        $this->withHeaders($this->signedPingHeaders($account))->postJson('/api/supply/ping')->assertOk();
    }
}
