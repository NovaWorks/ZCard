<?php

namespace Tests\Feature;

use App\Models\Card;
use App\Models\Category;
use App\Models\Commission;
use App\Models\Currency;
use App\Models\Merchant;
use App\Models\Product;
use App\Models\SubsiteDomain;
use App\Models\SubsiteLedgerEntry;
use App\Models\SubsiteProductSetting;
use App\Models\User;
use App\Support\CardCipher;
use App\Support\OrderService;
use App\Support\StorefrontConfig;
use App\Support\SubsitePricingService;
use App\Support\SubsiteWithdrawalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * 分站全流程端到端测试:
 * 创建分站 → 配域名 → 配商品加价 → 客户下单 → 付款结算(冻结) → 冻结到期 → 提现 → 后台审批
 */
class SubsiteEndToEndTest extends TestCase
{
    use RefreshDatabase;

    private function setupFullContext(): array
    {
        Currency::firstOrCreate(['code' => 'CNY'], ['name' => '人民币', 'symbol' => '¥', 'symbol_position' => 'before', 'decimal_places' => 2, 'exchange_rate' => '1', 'is_base' => true, 'is_enabled' => true, 'sort' => 0]);
        config(['zcard.features.sub_site' => true]);
        config(['zcard.features.distribution' => false]);
        config(['zcard.features.multi_merchant' => false]);
        StorefrontConfig::setMany([
            'subsite_enabled' => true,
            'subsite_default_confirm_days' => 7,
            'distribution_enabled' => false,
        ]);
        Cache::flush();

        // 主站商户 + 商品 + 卡密
        $mainUser = User::factory()->create();
        $mainMerchant = Merchant::firstOrCreate(['slug' => 'default'], ['user_id' => $mainUser->id, 'name' => '主站', 'status' => 1, 'commission_rate' => 0]);
        $cat = Category::create(['merchant_id' => $mainMerchant->id, 'name' => 'C', 'slug' => 'cat', 'sort' => 0]);
        $product = Product::create([
            'merchant_id' => $mainMerchant->id, 'category_id' => $cat->id, 'name' => 'Steam 充值卡', 'slug' => 'steam',
            'price' => 10000, 'factory_price' => 6000, 'stock_type' => 'card', 'delivery_mode' => 'status', 'status' => true, 'sort' => 0,
        ]);
        for ($i = 0; $i < 5; $i++) {
            Card::create(array_merge(
                ['product_id' => $product->id, 'dedup_hash' => null, 'status' => Card::STATUS_UNUSED],
                CardCipher::encryptWithHash('steam-key-'.$i.uniqid())
            ));
        }

        // 创建分站
        $owner = User::factory()->create(['username' => 'alice']);
        $subsite = Merchant::create([
            'user_id' => $owner->id, 'name' => "Alice's Shop", 'slug' => 'alice-shop',
            'status' => 1, 'commission_rate' => 0,
            'settings' => ['is_subsite' => true, 'default_markup_percent' => 0, 'max_markup_percent' => 50, 'settlement_confirm_days' => 7],
        ]);
        // 绑定域名
        SubsiteDomain::create([
            'merchant_id' => $subsite->id, 'domain' => 'shop.alice.com',
            'type' => 'custom', 'verification_status' => 'verified',
            'status' => 'active', 'is_primary' => true, 'verified_at' => now(),
        ]);
        // 配置商品加价(加价 10%)
        SubsiteProductSetting::create([
            'merchant_id' => $subsite->id, 'product_id' => $product->id, 'sku_id' => 0,
            'is_listed' => true, 'pricing_mode' => 'markup_percent', 'markup_percent' => 10,
        ]);

        return [$product, $subsite, $owner, $mainMerchant];
    }

    public function test_full_subsite_flow(): void
    {
        [$product, $subsite, $owner, $mainMerchant] = $this->setupFullContext();
        $orderService = app(OrderService::class);

        // Step 1: 分站定价正确(100 元 × 1.10 = 110 元)
        $pricingSvc = app(SubsitePricingService::class);
        $pricing = $pricingSvc->resolveUnitPrice($product, null, $subsite);
        $this->assertSame(11000, $pricing['price'], '分站加价后应为 110 元');
        $this->assertSame('markup_percent', $pricing['mode']);

        // Step 2: 客户在分站下单
        $buyer = User::factory()->create();
        request()->attributes->set('subsite', $subsite);
        $order = $orderService->createOrder($product->id, null, 1, [
            'contact' => 'buyer@test.com', 'user_id' => $buyer->id,
        ]);
        $this->assertSame(11000, (int) $order->amount, '订单金额应为分站加价后 110 元');
        $this->assertSame($subsite->id, $order->subsite_id, '订单应关联分站');
        $this->assertSame(1000, (int) $order->subsite_profit, '分站利润应为 1000 分(110-100)');

        // Step 3: 订单快照存在
        $this->assertDatabaseHas('subsite_order_snapshots', [
            'order_id' => $order->id, 'merchant_id' => $subsite->id,
            'profit_amount' => 1000, 'profit_eligible' => true,
        ]);

        // Step 4: 付款 → 结算(冻结期 ledger)
        $orderService->markPaid($order->order_no);
        $ledger = SubsiteLedgerEntry::where('order_id', $order->id)->where('type', 'order_profit')->first();
        $this->assertNotNull($ledger, '付款后应有 order_profit ledger 条目');
        $this->assertSame(1000, (int) $ledger->amount, '利润金额应为 1000 分');
        $this->assertSame('pending', $ledger->status, '冻结期内应为 pending');
        $this->assertNotNull($ledger->available_at, '应有冻结到期时间');

        // Step 5: 冻结到期 → available
        SubsiteLedgerEntry::where('id', $ledger->id)->update([
            'status' => 'available', 'available_at' => now()->subMinute(),
        ]);

        // Step 6: 分站主提现(FIFO)
        $withdrawal = SubsiteWithdrawalService::request(
            $subsite->id, 1000, 'alipay', 'alice@alipay.com', 'Alice'
        );
        $this->assertSame(1000, (int) $withdrawal->amount, '提现金额应为 1000 分');
        $this->assertSame('pending', $withdrawal->status, '提现状态应为 pending');

        // Step 7: 提现后 ledger 应为 locked
        $lockedEntry = SubsiteLedgerEntry::where('order_id', $order->id)->first();
        $this->assertSame('locked', $lockedEntry->status, '提现后 ledger 应为 locked');
        $this->assertSame($withdrawal->id, $lockedEntry->withdraw_request_id, 'ledger 应关联提现单');

        // Step 8: 后台审批通过
        SubsiteWithdrawalService::approve($withdrawal->id);
        $this->assertSame('approved', $withdrawal->fresh()->status, '审批后提现应为 approved');
        $finalEntry = SubsiteLedgerEntry::where('order_id', $order->id)->first();
        $this->assertSame('withdrawn', $finalEntry->status, '审批后 ledger 应为 withdrawn');

        // Step 9: 验证不触发分销佣金(互斥)
        $this->assertEquals(0, Commission::where('order_id', $order->id)->count(), '分站订单不应触发分销佣金');
    }

    public function test_subsite_owner_self_purchase_blocks_profit(): void
    {
        [$product, $subsite, $owner] = $this->setupFullContext();
        request()->attributes->set('subsite', $subsite);

        // 分站主自己买
        $order = app(OrderService::class)->createOrder($product->id, null, 1, [
            'contact' => $owner->email, 'user_id' => $owner->id,
        ]);

        $this->assertSame(11000, (int) $order->amount, '订单照走(加价价)');
        $this->assertSame(0, (int) $order->subsite_profit, '自购利润应为 0');
        $this->assertDatabaseHas('subsite_order_snapshots', [
            'order_id' => $order->id, 'profit_eligible' => false,
            'profit_block_reason' => 'self_dealing_owner',
        ]);

        // 付款后无 ledger(利润被拦截)
        app(OrderService::class)->markPaid($order->order_no);
        $this->assertEquals(0, SubsiteLedgerEntry::where('order_id', $order->id)->count(), '自购不应产生 ledger');
    }
}
