<?php

namespace Tests\Feature;

use App\Supply\NonceStore;
use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

class NonceStoreTest extends TestCase
{
    use RefreshDatabase;

    public function test_first_use_returns_true_second_returns_false(): void
    {
        config(['zcard.supply.nonce_store' => 'cache']);
        $store = app(NonceStore::class);
        $nonce = 'test_nonce_' . uniqid();

        $this->assertTrue($store->remember($nonce, 300));
        $this->assertFalse($store->remember($nonce, 300)); // 重复
    }

    public function test_database_store_persists(): void
    {
        config(['zcard.supply.nonce_store' => 'database']);
        $store = app(NonceStore::class);
        $nonce = 'db_nonce_' . uniqid();

        $this->assertTrue($store->remember($nonce, 300));
        $this->assertDatabaseHas('supply_nonces', ['nonce' => $nonce]);
        $this->assertFalse($store->remember($nonce, 300));
    }
}
