<?php

namespace Tests\Feature;

use App\Models\Card;
use App\Models\Merchant;
use App\Models\Product;
use App\Models\SupplierAccount;
use App\Models\User;
use App\Supply\Exceptions\SupplyApiException;
use App\Supply\SupplyOrderService;
use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;

class SupplyOrderServiceTest extends TestCase
{
    use RefreshDatabase;

    private function makeMerchant(): Merchant
    {
        $user = User::factory()->create();
        return Merchant::create(['name' => 'M', 'slug' => 'm' . uniqid(), 'user_id' => $user->id, 'settings' => []]);
    }

    private function makeAccount(int $balance = 100000): SupplierAccount
    {
        return SupplierAccount::create([
            'name' => 'A', 'api_key' => 'ak', 'api_secret' => Crypt::encryptString('sk'),
            'balance' => $balance, 'status' => 'active',
        ]);
    }

    private function makeProductWithCards(int $price, int $cardCount): Product
    {
        $merchant = $this->makeMerchant();
        $p = Product::create([
            'merchant_id' => $merchant->id, 'name' => 'P', 'slug' => 'p' . uniqid(),
            'price' => $price, 'factory_price' => $price, 'stock_type' => 'card', 'status' => 1,
        ]);
        for ($i = 0; $i < $cardCount; $i++) {
            Card::create([
                'product_id' => $p->id, 'content' => 'card_' . $i,
                'content_hash' => hash('sha256', 'card_' . $i . uniqid()),
                'status' => Card::STATUS_UNUSED,
            ]);
        }
        return $p->fresh();
    }

    public function test_sync_order_deducts_balance_and_delivers_cards(): void
    {
        $account = $this->makeAccount(100000); // 1000 元
        $product = $this->makeProductWithCards(500, 3); // 5 元, 3 张卡
        $service = app(SupplyOrderService::class);

        $result = $service->createOrder($account, [
            'product_id' => $product->id, 'quantity' => 2,
            'downstream_order_no' => 'DOWN-1',
        ], 'sync');

        $this->assertSame(1000, $result['amount']); // 500×2 分
        $this->assertCount(2, $result['cards']);
        $this->assertSame(99000, (int) $account->fresh()->balance); // 扣 1000 分
        $this->assertDatabaseHas('supply_orders', ['downstream_order_no' => 'DOWN-1']);
        $this->assertDatabaseHas('supplier_ledger_entries', ['type' => 'order', 'amount' => -1000]);
    }

    public function test_idempotent_same_downstream_no_returns_existing(): void
    {
        $account = $this->makeAccount(100000);
        $product = $this->makeProductWithCards(500, 5);
        $service = app(SupplyOrderService::class);

        $first = $service->createOrder($account, ['product_id' => $product->id, 'quantity' => 1, 'downstream_order_no' => 'DOWN-2'], 'sync');
        $second = $service->createOrder($account, ['product_id' => $product->id, 'quantity' => 1, 'downstream_order_no' => 'DOWN-2'], 'sync');

        $this->assertSame($first['supply_order_id'], $second['supply_order_id']);
        // qty=1 × 500 分 = 500;幂等只扣一次 → 100000-500=99500
        $this->assertSame(99500, (int) $account->fresh()->balance);
    }

    public function test_insufficient_balance_rejected(): void
    {
        $account = $this->makeAccount(300); // 只有 3 分
        $product = $this->makeProductWithCards(500, 3);
        $service = app(SupplyOrderService::class);

        try {
            $service->createOrder($account, ['product_id' => $product->id, 'quantity' => 1, 'downstream_order_no' => 'DOWN-3'], 'sync');
            $this->fail('应抛余额不足异常');
        } catch (SupplyApiException $e) {
            $this->assertSame('insufficient_balance', $e->errorCode);
        }
        $this->assertSame(300, (int) $account->fresh()->balance); // 余额未动
    }

    public function test_insufficient_stock_rejected(): void
    {
        $account = $this->makeAccount(100000);
        $product = $this->makeProductWithCards(500, 1); // 只有1张卡
        $service = app(SupplyOrderService::class);

        try {
            $service->createOrder($account, ['product_id' => $product->id, 'quantity' => 2, 'downstream_order_no' => 'DOWN-4'], 'sync');
            $this->fail('应抛库存不足异常');
        } catch (SupplyApiException $e) {
            $this->assertSame('insufficient_stock', $e->errorCode);
        }
    }
}
