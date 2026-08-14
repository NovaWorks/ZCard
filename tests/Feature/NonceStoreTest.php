<?php

namespace Tests\Feature;

use App\Supply\NonceStore;
use App\Support\StorefrontConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NonceStoreTest extends TestCase
{
    use RefreshDatabase;

    public function test_first_use_returns_true_second_returns_false(): void
    {
        StorefrontConfig::setMany(['supply_nonce_store' => 'cache']);
        $store = app(NonceStore::class);
        $nonce = 'test_nonce_'.uniqid();

        $this->assertTrue($store->remember('ns-a', $nonce, 300));
        $this->assertFalse($store->remember('ns-a', $nonce, 300)); // 重复
    }

    public function test_database_store_persists(): void
    {
        StorefrontConfig::setMany(['supply_nonce_store' => 'database']);
        $store = app(NonceStore::class);
        $nonce = 'db_nonce_'.uniqid();

        $this->assertTrue($store->remember('ns-b', $nonce, 300));
        // v1.12.90+:存 sha256 定长摘要(修复 nonce 列 varchar(64) 溢出/前缀互撞)
        $this->assertDatabaseHas('supply_nonces', ['nonce' => hash('sha256', 'ns-b|'.$nonce)]);
        $this->assertFalse($store->remember('ns-b', $nonce, 300));
    }

    public function test_namespace_isolates_nonces(): void
    {
        StorefrontConfig::setMany(['supply_nonce_store' => 'cache']);
        $store = app(NonceStore::class);
        $nonce = 'shared_nonce_'.uniqid();

        // 同一 nonce 在不同命名空间(账号)下互不影响。
        $this->assertTrue($store->remember('ns-1', $nonce, 300));
        $this->assertTrue($store->remember('ns-2', $nonce, 300));
    }
}
