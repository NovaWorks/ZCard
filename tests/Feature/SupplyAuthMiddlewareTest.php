<?php

namespace Tests\Feature;

use App\Models\SupplierAccount;
use App\Supply\HmacSigner;
use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

class SupplyAuthMiddlewareTest extends TestCase
{
    use RefreshDatabase;

    private function signedHeaders(SupplierAccount $account, string $method, string $path, string $body = ''): array
    {
        $ts = (string) time();
        $nonce = 'n' . uniqid();
        $signString = HmacSigner::buildSignString($method, $path, $ts, $nonce, md5($body));
        // 测试里 api_secret 存明文(不走加密),getRawOriginal 取明文算签名
        $sig = HmacSigner::sign($account->getRawOriginal('api_secret'), $signString);

        return [
            'X-Supply-Key' => $account->api_key,
            'X-Supply-Timestamp' => $ts,
            'X-Supply-Nonce' => $nonce,
            'X-Supply-Signature' => $sig,
        ];
    }

    public function test_valid_signature_passes(): void
    {
        config(['zcard.features.supply' => true, 'zcard.supply.nonce_store' => 'cache']);
        $account = SupplierAccount::create([
            'name' => 'A', 'api_key' => 'ak1', 'api_secret' => 'sk1', 'balance' => 10000, 'status' => 'active',
        ]);

        $headers = $this->signedHeaders($account, 'POST', '/api/supply/ping', '[]');
        $resp = $this->withHeaders($headers)->postJson('/api/supply/ping');

        $resp->assertOk();
        $this->assertSame(10000, $resp->json('balance'));
    }

    public function test_missing_headers_rejected(): void
    {
        config(['zcard.features.supply' => true]);
        $resp = $this->postJson('/api/supply/ping');
        $resp->assertStatus(401);
    }

    public function test_invalid_signature_rejected(): void
    {
        config(['zcard.features.supply' => true, 'zcard.supply.nonce_store' => 'cache']);
        $account = SupplierAccount::create([
            'name' => 'A', 'api_key' => 'ak1', 'api_secret' => 'sk1', 'status' => 'active',
        ]);

        $resp = $this->withHeaders([
            'X-Supply-Key' => 'ak1',
            'X-Supply-Timestamp' => (string) time(),
            'X-Supply-Nonce' => 'n1',
            'X-Supply-Signature' => 'bogus',
        ])->postJson('/api/supply/ping');

        $resp->assertStatus(401)->assertJson(['error_code' => 'invalid_signature']);
    }

    public function test_expired_timestamp_rejected(): void
    {
        config(['zcard.features.supply' => true, 'zcard.supply.timestamp_skew' => 300]);
        $account = SupplierAccount::create([
            'name' => 'A', 'api_key' => 'ak1', 'api_secret' => 'sk1', 'status' => 'active',
        ]);
        $oldTs = (string) (time() - 600); // 超 300s 窗口
        $nonce = 'n' . uniqid();
        $signString = HmacSigner::buildSignString('POST', '/api/supply/ping', $oldTs, $nonce, md5(''));
        $sig = HmacSigner::sign('sk1', $signString);

        $resp = $this->withHeaders([
            'X-Supply-Key' => 'ak1', 'X-Supply-Timestamp' => $oldTs,
            'X-Supply-Nonce' => $nonce, 'X-Supply-Signature' => $sig,
        ])->postJson('/api/supply/ping');

        $resp->assertStatus(401)->assertJson(['error_code' => 'timestamp_expired']);
    }

    public function test_disabled_account_rejected(): void
    {
        config(['zcard.features.supply' => true, 'zcard.supply.nonce_store' => 'cache']);
        $account = SupplierAccount::create([
            'name' => 'A', 'api_key' => 'ak1', 'api_secret' => 'sk1', 'status' => 'disabled',
        ]);

        $headers = $this->signedHeaders($account, 'POST', '/api/supply/ping');
        $resp = $this->withHeaders($headers)->postJson('/api/supply/ping');

        $resp->assertStatus(401)->assertJson(['error_code' => 'unauthorized']);
    }
}
