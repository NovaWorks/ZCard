<?php

namespace Tests\Feature;

use App\Models\Merchant;
use App\Models\Order;
use App\Models\Product;
use App\Models\SupplierAccount;
use App\Models\SupplierLedgerEntry;
use App\Models\SupplierProductPrice;
use App\Models\SupplyNonce;
use App\Models\SupplyOrder;
use App\Models\SupplySource;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

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

    public function test_product_url_uses_verified_snapshot_or_explicit_template(): void
    {
        $source = SupplySource::create([
            'name' => 'ACG 货源',
            'driver' => 'acg_faka',
            'base_url' => 'https://up.example.com',
            'credentials' => [],
            'settings' => [],
            'status' => 'active',
        ]);

        // API 对接 code 不是公开商品 ID，未取得真实分享链接时不能猜测 URL。
        $this->assertNull($source->productUrlFor('RANDOM-CODE'));
        $this->assertSame(
            'https://up.example.com/item/101',
            $source->productUrlFor('RANDOM-CODE', 'https://up.example.com/item/101')
        );

        // 管理员显式配置的模板仍保持最高优先级。
        $source->update([
            'settings' => ['product_url_template' => '{base}/custom/{code}'],
        ]);
        $this->assertSame(
            'https://up.example.com/custom/RANDOM-CODE',
            $source->fresh()->productUrlFor('RANDOM-CODE', 'https://up.example.com/item/101')
        );
    }

    public function test_supplier_account_balance_default_zero(): void
    {
        $account = SupplierAccount::create([
            'name' => '下游A',
            'api_key' => 'k'.uniqid(),
            'api_secret' => 'encrypted_secret',
        ]);
        $this->assertSame(0, (int) $account->fresh()->balance);
        $this->assertTrue($account->fresh()->isActive());
    }

    public function test_supplier_ledger_entry_create(): void
    {
        $account = SupplierAccount::create([
            'name' => '下游A', 'api_key' => 'k'.uniqid(), 'api_secret' => 's',
        ]);
        $entry = SupplierLedgerEntry::create([
            'supplier_account_id' => $account->id,
            'type' => SupplierLedgerEntry::TYPE_RECHARGE,
            'amount' => 10000,
            'balance_after' => 10000,
            'idempotency_key' => 'recharge_'.$account->id.'_1',
            'remark' => '首次充值',
        ]);
        $this->assertDatabaseHas('supplier_ledger_entries', ['id' => $entry->id, 'amount' => 10000]);
    }

    public function test_supplier_product_price_create(): void
    {
        $merchant = $this->makeMerchant();
        $product = Product::create([
            'merchant_id' => $merchant->id, 'name' => 'P', 'slug' => 'p-'.uniqid(),
            'price' => 500, 'factory_price' => 400, 'stock_type' => 'card', 'status' => 1,
        ]);
        $account = SupplierAccount::create(['name' => 'A', 'api_key' => 'k'.uniqid(), 'api_secret' => 's']);

        $price = SupplierProductPrice::create([
            'supplier_account_id' => $account->id, 'product_id' => $product->id,
            'sku_id' => null, 'price' => 450,
        ]);
        $this->assertDatabaseHas('supplier_product_prices', ['id' => $price->id, 'price' => 450]);
    }

    public function test_supply_order_create(): void
    {
        $merchant = $this->makeMerchant();
        $product = Product::create([
            'merchant_id' => $merchant->id, 'name' => 'P', 'slug' => 'p-'.uniqid(),
            'price' => 500, 'factory_price' => 400, 'stock_type' => 'card', 'status' => 1,
        ]);
        $order = Order::create([
            'order_no' => 'ORD'.uniqid(), 'merchant_id' => $merchant->id, 'product_id' => $product->id,
            'quantity' => 1, 'amount' => 500, 'status' => 'paid',
        ]);
        $account = SupplierAccount::create(['name' => 'A', 'api_key' => 'k'.uniqid(), 'api_secret' => 's']);

        $supplyOrder = SupplyOrder::create([
            'supplier_account_id' => $account->id, 'order_id' => $order->id,
            'downstream_order_no' => 'DOWN-'.uniqid(), 'fulfillment_mode' => 'sync',
        ]);
        $this->assertDatabaseHas('supply_orders', ['id' => $supplyOrder->id]);
    }

    public function test_supply_nonce_create(): void
    {
        $nonce = SupplyNonce::create([
            'nonce' => 'n-'.uniqid(), 'expires_at' => now()->addMinutes(5),
        ]);
        $this->assertDatabaseHas('supply_nonces', ['id' => $nonce->id]);
    }

    private function makeMerchant(): Merchant
    {
        return Merchant::create([
            'user_id' => User::factory()->create()->id,
            'name' => 'M', 'slug' => 'm-'.uniqid(),
            'status' => 1, 'commission_rate' => 0,
        ]);
    }
}
