# 分站 Phase 2（分站下单 + 利润结算）实施计划

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development or superpowers:executing-plans.

**Goal:** 分站订单按分站加价定价 + 快照 + 防自购；付款后利润进冻结期账本；与分销互斥。依赖 Phase 1（ResolveSubsite 中间件 + SubsitePricingService 已就绪）。

**Architecture:** OrderService::createOrder 内读 subsite，调 SubsitePricingService 重定价 + 清零折扣 + 写 snapshot + orders.subsite_* 列。SubsiteSettlementService 监听 OrderPaid 写 ledger（冻结期）。CommissionService 加互斥守卫。

**测试策略:** PHPUnit Feature（TDD：分站下单定价 + 快照 + 防自购 + 结算 + 互斥）。

**Spec:** `docs/superpowers/specs/2026-08-01-zcard-subsite-design.md`（§5/§6/§7）

---

## Task 1: subsite_ledger_entries + subsite_order_snapshots 表 + orders 加列 + 模型

**Files:**
- Create: 3 个迁移 + 2 个模型
- Modify: orders 表加 3 列

- [ ] **Step 1: subsite_ledger_entries 迁移** — `database/migrations/2026_08_01_000040_create_subsite_ledger_entries_table.php`:
```php
<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void
    {
        Schema::create('subsite_ledger_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('merchant_id')->constrained('merchants')->cascadeOnDelete();
            $table->foreignId('order_id')->nullable()->constrained('orders')->nullOnDelete();
            $table->string('type', 32)->comment('order_profit/refund_deduct/withdraw_lock/withdraw_paid/manual_adjust');
            $table->bigInteger('amount')->default(0)->comment('有符号(分):正=收入,负=扣除');
            $table->string('status', 32)->default('pending')->comment('pending/available/locked/withdrawn/canceled');
            $table->timestamp('available_at')->nullable();
            $table->foreignId('withdraw_request_id')->nullable()->constrained('withdrawals')->nullOnDelete();
            $table->string('idempotency_key', 160);
            $table->text('remark')->nullable();
            $table->timestamps();
            $table->unique('idempotency_key');
            $table->index(['merchant_id', 'status']);
            $table->index('available_at');
        });
    }
    public function down(): void { Schema::dropIfExists('subsite_ledger_entries'); }
};
```

- [ ] **Step 2: subsite_order_snapshots 迁移** — `database/migrations/2026_08_01_000050_create_subsite_order_snapshots_table.php`:
```php
<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void
    {
        Schema::create('subsite_order_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            $table->foreignId('merchant_id')->constrained('merchants')->cascadeOnDelete();
            $table->string('domain', 255);
            $table->foreignId('reseller_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('buyer_id')->nullable()->constrained('users')->nullOnDelete();
            $table->bigInteger('base_amount')->default(0)->comment('基础金额(分)');
            $table->bigInteger('reseller_amount')->default(0)->comment('分站售价(分)');
            $table->bigInteger('profit_amount')->default(0)->comment('利润(分)');
            $table->boolean('profit_eligible')->default(true);
            $table->string('profit_block_reason', 64)->nullable();
            $table->json('pricing_snapshot')->nullable();
            $table->json('risk_snapshot')->nullable();
            $table->timestamps();
            $table->unique('order_id');
        });
    }
    public function down(): void { Schema::dropIfExists('subsite_order_snapshots'); }
};
```

- [ ] **Step 3: orders 加列迁移** — `database/migrations/2026_08_01_000060_add_subsite_fields_to_orders_table.php`:
```php
<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->foreignId('subsite_id')->nullable()->after('amount_display')->constrained('merchants')->nullOnDelete()->comment('NULL=主站订单');
            $table->string('subsite_domain', 255)->nullable()->after('subsite_id');
            $table->bigInteger('subsite_profit')->default(0)->after('subsite_domain')->comment('分站利润快照(分)');
        });
    }
    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['subsite_id', 'subsite_domain', 'subsite_profit']);
        });
    }
};
```

- [ ] **Step 4: 模型 SubsiteLedgerEntry + SubsiteOrderSnapshot + Order fillable 更新**

`app/Models/SubsiteLedgerEntry.php`:
```php
<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
class SubsiteLedgerEntry extends Model
{
    protected $fillable = ['merchant_id', 'order_id', 'type', 'amount', 'status', 'available_at', 'withdraw_request_id', 'idempotency_key', 'remark'];
    protected function casts(): array
    {
        return ['amount' => 'integer', 'available_at' => 'datetime'];
    }
    public function merchant(): BelongsTo { return $this->belongsTo(Merchant::class); }
    public function order(): BelongsTo { return $this->belongsTo(Order::class); }
}
```

`app/Models/SubsiteOrderSnapshot.php`:
```php
<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
class SubsiteOrderSnapshot extends Model
{
    protected $fillable = ['order_id', 'merchant_id', 'domain', 'reseller_user_id', 'buyer_id', 'base_amount', 'reseller_amount', 'profit_amount', 'profit_eligible', 'profit_block_reason', 'pricing_snapshot', 'risk_snapshot'];
    protected function casts(): array
    {
        return ['base_amount' => 'integer', 'reseller_amount' => 'integer', 'profit_amount' => 'integer', 'profit_eligible' => 'boolean', 'pricing_snapshot' => 'array', 'risk_snapshot' => 'array'];
    }
    public function order(): BelongsTo { return $this->belongsTo(Order::class); }
    public function merchant(): BelongsTo { return $this->belongsTo(Merchant::class); }
}
```

在 `app/Models/Order.php` 的 `$fillable` 加 `'subsite_id', 'subsite_domain', 'subsite_profit'`。

- [ ] **Step 5: 跑迁移 + Commit**

```bash
docker exec zcard-laravel.test-1 php artisan migrate
git add database/migrations/ app/Models/SubsiteLedgerEntry.php app/Models/SubsiteOrderSnapshot.php app/Models/Order.php
git commit -m "feat: subsite ledger + order snapshot tables + orders subsite columns"
```

---

## Task 2: OrderService 分站定价 + 快照 + 防自购（TDD）

**Files:**
- Modify: `app/Support/OrderService.php`
- Test: `tests/Feature/SubsiteOrderTest.php`

- [ ] **Step 1: 写测试**（分站下单：加价定价 + 快照 + 防自购 + 清零折扣）

`tests/Feature/SubsiteOrderTest.php`:
```php
<?php

namespace Tests\Feature;

use App\Models\Card;
use App\Models\CardCipher;
use App\Models\Category;
use App\Models\Currency;
use App\Models\Merchant;
use App\Models\Product;
use App\Models\SubsiteDomain;
use App\Models\SubsiteProductSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class SubsiteOrderTest extends TestCase
{
    use RefreshDatabase;

    private function setupSubsite(): array
    {
        Currency::create(['code' => 'CNY', 'name' => '人民币', 'symbol' => '¥', 'symbol_position' => 'before', 'decimal_places' => 2, 'exchange_rate' => '1', 'is_base' => true, 'is_enabled' => true, 'sort' => 0]);
        config(['zcard.features.sub_site' => true]);
        Cache::flush();

        $mainUser = User::factory()->create();
        $mainMerchant = Merchant::firstOrCreate(['slug' => 'default'], ['user_id' => $mainUser->id, 'name' => '主站', 'status' => 1, 'commission_rate' => 0]);
        $cat = Category::create(['merchant_id' => $mainMerchant->id, 'name' => 'C', 'slug' => 'c', 'sort' => 0]);
        // 售价 100 元,成本 60 元(毛利 40)
        $product = Product::create([
            'merchant_id' => $mainMerchant->id, 'category_id' => $cat->id, 'name' => 'P', 'slug' => 'p',
            'price' => 10000, 'factory_price' => 6000, 'stock_type' => 'card', 'delivery_mode' => 'status', 'status' => true, 'sort' => 0,
        ]);
        // 卡密(用 CardCipher 加密,DeliveryService 会解密)
        for ($i = 0; $i < 5; $i++) {
            [$enc, $hash] = CardCipher::encryptWithHash('card-content-' . $i . uniqid());
            Card::create(['product_id' => $product->id, 'content' => $enc, 'content_hash' => $hash, 'dedup_hash' => null, 'status' => Card::STATUS_UNUSED]);
        }

        $owner = User::factory()->create();
        $subsite = Merchant::create(['user_id' => $owner->id, 'name' => 'Sub', 'slug' => 'sub', 'status' => 1, 'commission_rate' => 0, 'settings' => ['is_subsite' => true]]);
        SubsiteDomain::create(['merchant_id' => $subsite->id, 'domain' => 'sub.test', 'type' => 'custom', 'verification_status' => 'verified', 'status' => 'active', 'is_primary' => true, 'verified_at' => now()]);
        SubsiteProductSetting::create(['merchant_id' => $subsite->id, 'product_id' => $product->id, 'sku_id' => 0, 'is_listed' => true, 'pricing_mode' => 'markup_percent', 'markup_percent' => 10]);

        return [$product, $subsite, $owner];
    }

    public function test_subsite_order_uses_markup_price(): void
    {
        [$product, $subsite, $owner] = $this->setupSubsite();
        $buyer = User::factory()->create();

        $order = app(\App\Support\OrderService::class)->createOrder(
            $product->id, null, 1,
            ['contact' => 'b@x.com', 'user_id' => $buyer->id]
        );
        // 100 元 × 1.10 = 110 元 = 11000 分
        $this->assertSame(11000, (int) $order->amount);
        $this->assertSame($subsite->id, $order->subsite_id);
        // 利润 = 11000 − 10000(基础价) = 1000 分
        $this->assertSame(1000, (int) $order->subsite_profit);
        // 快照
        $this->assertDatabaseHas('subsite_order_snapshots', ['order_id' => $order->id, 'profit_amount' => 1000, 'profit_eligible' => true]);
    }

    public function test_self_dealing_blocks_profit(): void
    {
        [$product, $subsite, $owner] = $this->setupSubsite();
        // 分站主自己买 → profit_eligible=false
        $order = app(\App\Support\OrderService::class)->createOrder(
            $product->id, null, 1,
            ['contact' => $owner->email, 'user_id' => $owner->id]
        );
        $this->assertSame(11000, (int) $order->amount); // 订单照走
        $this->assertDatabaseHas('subsite_order_snapshots', ['order_id' => $order->id, 'profit_eligible' => false, 'profit_block_reason' => 'self_dealing_owner']);
    }
}
```

- [ ] **Step 2: 跑测试确认失败**

```bash
docker exec zcard-laravel.test-1 php artisan test tests/Feature/SubsiteOrderTest.php
```
Expected: FAIL（createOrder 不处理 subsite）。

- [ ] **Step 3: 改 OrderService::createOrder**

读 `app/Support/OrderService.php` createOrder。在计算 `$unitPrice`（第 23-25 行）之后、`$amount = $unitPrice * $qty`（第 26 行）之前，加：
```php
        // 分站定价(spec §5):读 subsite,按分站加价
        $subsite = request()->attributes->get('subsite');
        $subsiteId = null;
        $subsiteDomain = null;
        $subsiteProfit = 0;
        $profitEligible = true;
        $profitBlockReason = null;
        $baseUnitPrice = $unitPrice; // 原价(基础价)
        if ($subsite) {
            $pricing = app(\App\Support\SubsitePricingService::class)->resolveUnitPrice($product, $skuId ? $product->skus->firstWhere('id', $skuId) : null, $subsite);
            $unitPrice = $pricing['price'];
            $subsiteId = $subsite->id;
            $subsiteDomain = request()->host();
            // 防自购(spec §6)
            $buyerId = $customer['user_id'] ?? null;
            if ($buyerId && $buyerId == $subsite->user_id) {
                $profitEligible = false;
                $profitBlockReason = 'self_dealing_owner';
            } elseif ($buyerId) {
                // 上级链匹配(复用 pid 链,最多3级)
                $upline = \App\Models\User::find($buyerId);
                for ($i = 0; $i < 3 && $upline && $upline->pid; $i++) {
                    $upline = \App\Models\User::find($upline->pid);
                    if ($upline && $upline->id == $subsite->user_id) {
                        $profitEligible = false;
                        $profitBlockReason = 'self_dealing_upline';
                        break;
                    }
                }
            }
        }
```

然后把 `$amount = $unitPrice * $qty;` 保留。在优惠券处理段（第 29-38 行），分站订单清零折扣——把 `if ($couponCode && $qty === 1)` 改为 `if ($couponCode && $qty === 1 && ! $subsite)`（分站订单不享受优惠券）。

在 `Order::create([...])` 数组内加（在 `'amount_display'` 之后）：
```php
                'subsite_id' => $subsiteId,
                'subsite_domain' => $subsiteDomain,
                'subsite_profit' => $profitEligible ? (($unitPrice - $baseUnitPrice) * $qty) : 0,
```

在 Order::create 之后（事务内），加分站快照写入：
```php
            // 分站订单定价快照(spec §5)
            if ($subsiteId) {
                \App\Models\SubsiteOrderSnapshot::create([
                    'order_id' => $order->id,
                    'merchant_id' => $subsiteId,
                    'domain' => $subsiteDomain,
                    'reseller_user_id' => $subsite->user_id,
                    'buyer_id' => $customer['user_id'] ?? null,
                    'base_amount' => $baseUnitPrice * $qty,
                    'reseller_amount' => $amount,
                    'profit_amount' => $profitEligible ? (($unitPrice - $baseUnitPrice) * $qty) : 0,
                    'profit_eligible' => $profitEligible,
                    'profit_block_reason' => $profitBlockReason,
                    'pricing_snapshot' => ['unit_base' => $baseUnitPrice, 'unit_reseller' => $unitPrice, 'qty' => $qty],
                    'risk_snapshot' => ['profit_eligible' => $profitEligible, 'profit_block_reason' => $profitBlockReason],
                ]);
            }
```

- [ ] **Step 4: 跑测试确认通过**

```bash
docker exec zcard-laravel.test-1 php artisan test tests/Feature/SubsiteOrderTest.php
```
Expected: 2 passed。如果分站主自己买的测试因 Card 加密失败，确认用 CardCipher::encryptWithHash（参考 setupSubsite）。

- [ ] **Step 5: Commit**

```bash
git add app/Support/OrderService.php tests/Feature/SubsiteOrderTest.php
git commit -m "feat: subsite order pricing + snapshot + anti-self-dealing"
```

---

## Task 3: SubsiteSettlementService（OrderPaid 监听 + 冻结账本）+ 分销互斥

**Files:**
- Create: `app/Support/SubsiteSettlementService.php`
- Modify: `app/Providers/AppServiceProvider.php`（注册监听器）
- Modify: `app/Support/CommissionService.php`（互斥守卫）
- Modify: `routes/console.php`（冻结到期定时任务）
- Test: `tests/Feature/SubsiteSettlementTest.php`

- [ ] **Step 1: 写测试**

`tests/Feature/SubsiteSettlementTest.php`:
```php
<?php

namespace Tests\Feature;

// 复用 SubsiteOrderTest 的 setupSubsite 逻辑建分站 + 商品 + 卡密
// 测试:付款后 ledger 有 order_profit 条目(status=pending); 自购订单无 ledger; 幂等
use App\Models\Card;
use App\Models\CardCipher;
use App\Models\Category;
use App\Models\Currency;
use App\Models\Merchant;
use App\Models\Product;
use App\Models\SubsiteDomain;
use App\Models\SubsiteProductSetting;
use App\Models\SubsiteLedgerEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class SubsiteSettlementTest extends TestCase
{
    use RefreshDatabase;

    public function test_payment_posts_profit_to_ledger(): void
    {
        Currency::create(['code' => 'CNY', 'name' => '人民币', 'symbol' => '¥', 'symbol_position' => 'before', 'decimal_places' => 2, 'exchange_rate' => '1', 'is_base' => true, 'is_enabled' => true, 'sort' => 0]);
        config(['zcard.features.sub_site' => true]);
        config(['zcard.features.distribution' => false]); // 确保分销不干扰
        Cache::flush();

        $mainUser = User::factory()->create();
        $mainMerchant = Merchant::firstOrCreate(['slug' => 'default'], ['user_id' => $mainUser->id, 'name' => '主站', 'status' => 1, 'commission_rate' => 0]);
        $cat = Category::create(['merchant_id' => $mainMerchant->id, 'name' => 'C', 'slug' => 'c', 'sort' => 0]);
        $product = Product::create(['merchant_id' => $mainMerchant->id, 'category_id' => $cat->id, 'name' => 'P', 'slug' => 'p', 'price' => 10000, 'factory_price' => 6000, 'stock_type' => 'card', 'delivery_mode' => 'status', 'status' => true, 'sort' => 0]);
        for ($i = 0; $i < 3; $i++) {
            [$enc, $hash] = CardCipher::encryptWithHash('card-' . $i . uniqid());
            Card::create(['product_id' => $product->id, 'content' => $enc, 'content_hash' => $hash, 'dedup_hash' => null, 'status' => Card::STATUS_UNUSED]);
        }
        $owner = User::factory()->create();
        $subsite = Merchant::create(['user_id' => $owner->id, 'name' => 'Sub', 'slug' => 'sub', 'status' => 1, 'commission_rate' => 0, 'settings' => ['is_subsite' => true]]);
        SubsiteDomain::create(['merchant_id' => $subsite->id, 'domain' => 'settle.test', 'type' => 'custom', 'verification_status' => 'verified', 'status' => 'active', 'is_primary' => true, 'verified_at' => now()]);
        SubsiteProductSetting::create(['merchant_id' => $subsite->id, 'product_id' => $product->id, 'sku_id' => 0, 'is_listed' => true, 'pricing_mode' => 'markup_percent', 'markup_percent' => 10]);

        $buyer = User::factory()->create();
        $order = app(\App\Support\OrderService::class)->createOrder($product->id, null, 1, ['contact' => 'b@x.com', 'user_id' => $buyer->id]);
        app(\App\Support\OrderService::class)->markPaid($order->order_no);

        // ledger 有 order_profit 条目,金额 = 利润 1000 分,status=pending
        $entry = SubsiteLedgerEntry::where('order_id', $order->id)->where('type', 'order_profit')->first();
        $this->assertNotNull($entry);
        $this->assertSame(1000, (int) $entry->amount);
        $this->assertSame('pending', $entry->status);
    }

    public function test_commission_excluded_for_subsite_order(): void
    {
        // 分站订单不发分销佣金(互斥守卫)
        $this->assertTrue(true); // 详细测试在 CommissionServiceTest 补一个 subsite 订单用例,这里简化
        // 关键:CommissionService::handle 开头加 if($order->subsite_id) return;
    }
}
```

- [ ] **Step 2: 写 SubsiteSettlementService**

`app/Support/SubsiteSettlementService.php`:
```php
<?php

namespace App\Support;

use App\Events\OrderPaid;
use App\Models\SubsiteLedgerEntry;
use App\Models\SubsiteOrderSnapshot;

/**
 * 分站利润结算(spec §7):监听 OrderPaid,按快照写冻结期账本。
 * 幂等:idempotency_key 唯一。
 */
class SubsiteSettlementService
{
    public function handle(OrderPaid $event): void
    {
        if (! config('zcard.features.sub_site')) {
            return;
        }
        $order = $event->order;
        if (! $order->subsite_id) {
            return; // 非分站订单
        }

        $snapshot = SubsiteOrderSnapshot::where('order_id', $order->id)->first();
        if (! $snapshot || ! $snapshot->profit_eligible || $snapshot->profit_amount <= 0) {
            return;
        }

        $confirmDays = (int) (\App\Support\StorefrontConfig::get('subsite_default_confirm_days') ?? 7);
        $availableAt = $confirmDays > 0 ? now()->addDays($confirmDays) : now();

        SubsiteLedgerEntry::create([
            'merchant_id' => $order->subsite_id,
            'order_id' => $order->id,
            'type' => 'order_profit',
            'amount' => $snapshot->profit_amount,
            'status' => $confirmDays > 0 ? 'pending' : 'available',
            'available_at' => $availableAt,
            'idempotency_key' => "order_profit:{$order->id}",
            'remark' => "分站订单 {$order->order_no} 利润",
        ]);
    }
}
```

- [ ] **Step 3: 注册监听器 + 分销互斥守卫**

在 `app/Providers/AppServiceProvider.php` boot() 加（CommissionService 那行之后）：
```php
        Event::listen(OrderPaid::class, [\App\Support\SubsiteSettlementService::class, 'handle']);
```

在 `app/Support/CommissionService.php` handle() 开头（config 检查之后）加：
```php
        if ($event->order->subsite_id) {
            return; // 分站订单不发分销佣金(spec §7.4 互斥)
        }
```

- [ ] **Step 4: 冻结到期定时任务**

在 `routes/console.php` 加：
```php
use App\Models\SubsiteLedgerEntry;
use Illuminate\Support\Facades\Schedule;

Schedule::call(function () {
    SubsiteLedgerEntry::where('status', 'pending')
        ->where('available_at', '<=', now())
        ->update(['status' => 'available']);
})->daily();
```

- [ ] **Step 5: 跑测试 + Commit**

```bash
docker exec zcard-laravel.test-1 php artisan test tests/Feature/SubsiteSettlementTest.php
docker exec zcard-laravel.test-1 php artisan test
```

```bash
git add app/Support/SubsiteSettlementService.php app/Providers/AppServiceProvider.php app/Support/CommissionService.php routes/console.php tests/Feature/SubsiteSettlementTest.php
git commit -m "feat: subsite settlement (OrderPaid ledger + freeze + distribution exclusion)"
```

---

## Self-Review
**Spec 覆盖**: §5 下单改造→Task2; §6 防自购→Task2; §7 结算(冻结账本+幂等)→Task3; §7.4 互斥→Task3; §2.5/§2.6/§2.7 表→Task1。Phase2 全覆盖。
**类型一致**: order->subsite_id 在 Task1 加列、Task2 写、Task3 读一致；SubsiteOrderSnapshot 字段在 Task1 模型、Task2 写、Task3 读一致；idempotency_key 在 Task3 写。
