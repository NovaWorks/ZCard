<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\SupplierAccount;
use App\Models\SupplierLedgerEntry;
use App\Models\SupplySource;
use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

class SupplyModelsTest extends TestCase
{
    use RefreshDatabase;

    public function test_supply_source_credentials_are_encrypted(): void
    {
        $source = SupplySource::create([
            'name' => '测试货源',
            'driver' => 'dujiao_next',
            'base_url' => 'https://up.example.com',
            'credentials' => ['api_key' => 'ak123', 'api_secret' => 'sk456'],
            'status' => 'active',
        ]);

        // 读回时自动解密为数组
        $this->assertSame(['api_key' => 'ak123', 'api_secret' => 'sk456'], $source->fresh()->credentials);

        // 数据库里存的是密文(不含明文 ak123)
        $raw = \DB::table('supply_sources')->where('id', $source->id)->value('credentials');
        $this->assertStringNotContainsString('ak123', $raw);
    }

    public function test_supplier_account_balance_default_zero(): void
    {
        $account = SupplierAccount::create([
            'name' => '下游A',
            'api_key' => 'k' . uniqid(),
            'api_secret' => 'encrypted_secret',
        ]);
        $this->assertSame(0, (int) $account->fresh()->balance);
        $this->assertTrue($account->fresh()->isActive());
    }

    public function test_supplier_ledger_entry_create(): void
    {
        $account = SupplierAccount::create([
            'name' => '下游A', 'api_key' => 'k' . uniqid(), 'api_secret' => 's',
        ]);
        $entry = SupplierLedgerEntry::create([
            'supplier_account_id' => $account->id,
            'type' => SupplierLedgerEntry::TYPE_RECHARGE,
            'amount' => 10000,
            'balance_after' => 10000,
            'idempotency_key' => 'recharge_' . $account->id . '_1',
            'remark' => '首次充值',
        ]);
        $this->assertDatabaseHas('supplier_ledger_entries', ['id' => $entry->id, 'amount' => 10000]);
    }
}
