# 分站 Phase 3（分站主自助 + 白标 + FIFO 提现）实施计划

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development or superpowers:executing-plans.

**Goal:** 分站主在主站自助管理分站（开通/域名/商品配置/财务/FIFO提现）；分站域名访问时白标展示（站名/logo/公告）。依赖 Phase 1+2（中间件/定价/结算已就绪）。

**Architecture:** 分站主控制台 API 在主站路由组（auth:sanctum + 主站守卫中间件 RequireMainSite）。FIFO 提现服务消费 available ledger。storefront 前端加白标渲染 + 我的分站页。

**测试策略:** PHPUnit Feature（FIFO 提现逻辑）+ 前端 pnpm build 验证。

**Spec:** `docs/superpowers/specs/2026-08-01-zcard-subsite-design.md`（§7.3/§8/§8.3）

---

## Task 1: RequireMainSite 中间件（控制台只在主站）+ 分站主自助 API

**Files:**
- Create: `app/Http/Middleware/RequireMainSite.php`
- Create: `app/Http/Controllers/Api/SubsiteConsoleController.php`
- Modify: `bootstrap/app.php`（别名）、`routes/api.php`

- [ ] **Step 1: RequireMainSite 中间件**

`app/Http/Middleware/RequireMainSite.php`:
```php
<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/**
 * 分站管理控制台只在主站可访问(spec §8.2,参考 dujiao-next RequireMainTenantForResellerConsole)。
 * 当前请求来自分站域名(subsite 非空)→ 403。
 */
class RequireMainSite
{
    public function handle(Request $request, Closure $next): mixed
    {
        if ($request->attributes->get('subsite')) {
            return response()->json(['message' => '分站管理仅限主站操作'], 403);
        }
        return $next($request);
    }
}
```

- [ ] **Step 2: 注册别名**（bootstrap/app.php alias 加）
```php
            'require.main.site' => \App\Http\Middleware\RequireMainSite::class,
```

- [ ] **Step 3: SubsiteConsoleController（分站主自助）**

`app/Http/Controllers/Api/SubsiteConsoleController.php`:
```php
<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Merchant;
use App\Models\SubsiteDomain;
use App\Models\SubsiteLedgerEntry;
use App\Models\SubsiteOrderSnapshot;
use App\Models\SubsiteProductSetting;
use App\Models\Withdrawal;
use App\Support\WithdrawalService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * 分站主自助控制台(spec §8.2):只在主站可访问(RequireMainSite 守卫)。
 */
class SubsiteConsoleController extends Controller
{
    /** 我的分站(当前用户的分站) */
    public function mySubsite(Request $request): JsonResponse
    {
        $merchant = Merchant::where('user_id', $request->user()->id)
            ->where('settings->is_subsite', true)->first();
        if (! $merchant) {
            return response()->json(['message' => '您还没有分站'], 404);
        }
        return response()->json($merchant->load('domains'));
    }

    /** 分站财务概览 */
    public function finance(Request $request): JsonResponse
    {
        $merchant = $this->getMySubsite($request);
        if (! $merchant) return response()->json(['message' => '无分站'], 404);

        $available = SubsiteLedgerEntry::where('merchant_id', $merchant->id)->where('status', 'available')->sum('amount');
        $pending = SubsiteLedgerEntry::where('merchant_id', $merchant->id)->where('status', 'pending')->sum('amount');
        $total = SubsiteLedgerEntry::where('merchant_id', $merchant->id)->whereIn('type', ['order_profit', 'refund_deduct'])->sum('amount');

        return response()->json([
            'total_profit' => (int) $total,
            'available' => (int) $available,
            'pending' => (int) $pending,
        ]);
    }

    /** 利润账本明细 */
    public function ledger(Request $request): JsonResponse
    {
        $merchant = $this->getMySubsite($request);
        if (! $merchant) return response()->json(['message' => '无分站'], 404);
        return response()->json(
            SubsiteLedgerEntry::where('merchant_id', $merchant->id)->orderByDesc('id')->limit(100)->get()
        );
    }

    /** 域名绑定/解绑 */
    public function bindDomain(Request $request): JsonResponse
    {
        $merchant = $this->getMySubsite($request);
        if (! $merchant) return response()->json(['message' => '无分站'], 404);
        $data = $request->validate([
            'domain' => 'required|string|max:255',
            'type' => 'required|in:subdomain,custom',
        ]);
        $domain = strtolower(trim($data['domain']));
        $row = SubsiteDomain::create([
            'merchant_id' => $merchant->id,
            'domain' => $domain,
            'type' => $data['type'],
            'verification_token' => $data['type'] === 'custom' ? \Illuminate\Support\Str::random(32) : null,
            'verification_status' => $data['type'] === 'subdomain' ? 'verified' : 'pending',
            'status' => $data['type'] === 'subdomain' ? 'active' : 'pending_review',
            'verified_at' => $data['type'] === 'subdomain' ? now() : null,
            'is_primary' => ! SubsiteDomain::where('merchant_id', $merchant->id)->exists(),
        ]);
        return response()->json($row, 201);
    }

    /** 商品配置(列表 + upsert,参考 AdminSubsiteController) */
    public function productSettings(Request $request): JsonResponse
    {
        $merchant = $this->getMySubsite($request);
        if (! $merchant) return response()->json(['message' => '无分站'], 404);
        return response()->json(
            SubsiteProductSetting::where('merchant_id', $merchant->id)->with('product:id,name,slug,price')->get()
        );
    }

    public function upsertProductSetting(Request $request): JsonResponse
    {
        $merchant = $this->getMySubsite($request);
        if (! $merchant) return response()->json(['message' => '无分站'], 404);
        $data = $request->validate([
            'product_id' => 'required|exists:products,id',
            'is_listed' => 'boolean',
            'pricing_mode' => 'sometimes|in:inherit,markup_percent,fixed_markup,fixed_price',
            'markup_percent' => 'nullable|numeric|min:0',
            'fixed_markup_amount' => 'nullable|integer|min:0',
            'fixed_price_amount' => 'nullable|integer|min:0',
        ]);
        $data['merchant_id'] = $merchant->id;
        $data['sku_id'] = 0;
        $setting = SubsiteProductSetting::updateOrCreate(
            ['merchant_id' => $merchant->id, 'product_id' => $data['product_id'], 'sku_id' => 0],
            $data
        );
        return response()->json($setting, 201);
    }

    private function getMySubsite(Request $request): ?Merchant
    {
        return Merchant::where('user_id', $request->user()->id)->where('settings->is_subsite', true)->first();
    }
}
```

- [ ] **Step 4: 路由（auth + RequireMainSite 守卫组）**

在 `routes/api.php` 的 auth:sanctum 组内加：
```php
    // 分站主自助控制台(只在主站,RequireMainSite 守卫)
    Route::middleware('require.main.site')->prefix('subsite-console')->group(function () {
        Route::get('/', [SubsiteConsoleController::class, 'mySubsite']);
        Route::get('/finance', [SubsiteConsoleController::class, 'finance']);
        Route::get('/ledger', [SubsiteConsoleController::class, 'ledger']);
        Route::post('/domains', [SubsiteConsoleController::class, 'bindDomain']);
        Route::get('/product-settings', [SubsiteConsoleController::class, 'productSettings']);
        Route::post('/product-settings', [SubsiteConsoleController::class, 'upsertProductSetting']);
    });
```
use 加 `use App\Http\Controllers\Api\SubsiteConsoleController;`

- [ ] **Step 5: 验证 + Commit**

```bash
docker exec zcard-laravel.test-1 php artisan route:list --path=api/subsite-console | head
docker exec zcard-laravel.test-1 php artisan test
git add app/Http/Middleware/RequireMainSite.php app/Http/Controllers/Api/SubsiteConsoleController.php bootstrap/app.php routes/api.php
git commit -m "feat: subsite console API (main-site-only guard + finance/ledger/domains/products)"
```

---

## Task 2: FIFO 提现服务（SubsiteWithdrawalService）

**Files:**
- Create: `app/Support/SubsiteWithdrawalService.php`
- Create: `app/Http/Controllers/Api/SubsiteWithdrawalController.php`
- Modify: `routes/api.php`
- Test: `tests/Feature/SubsiteWithdrawalTest.php`

- [ ] **Step 1: 写测试**

`tests/Feature/SubsiteWithdrawalTest.php`:
```php
<?php

namespace Tests\Feature;

use App\Models\Merchant;
use App\Models\SubsiteLedgerEntry;
use App\Models\User;
use App\Models\Withdrawal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SubsiteWithdrawalTest extends TestCase
{
    use RefreshDatabase;

    public function test_fifo_withdraw_consumes_available_entries(): void
    {
        $user = User::factory()->create(['balance' => 0]);
        $merchant = Merchant::create(['user_id' => $user->id, 'name' => 'Sub', 'slug' => 's', 'status' => 1, 'commission_rate' => 0, 'settings' => ['is_subsite' => true]]);
        // 3 笔 available 利润: 300, 500, 200 (总 1000 分)
        foreach ([300, 500, 200] as $amt) {
            SubsiteLedgerEntry::create(['merchant_id' => $merchant->id, 'type' => 'order_profit', 'amount' => $amt, 'status' => 'available', 'idempotency_key' => 'k' . $amt, 'available_at' => now()->subDay()]);
        }

        // 提现 700 分 → FIFO 消费 300+500 第一二笔,第三笔 200 剩
        $w = \App\Support\SubsiteWithdrawalService::request($merchant->id, 700, 'alipay', 'acc@test.com', 'Test');
        $this->assertSame(700, (int) $w->amount);

        $locked = SubsiteLedgerEntry::where('merchant_id', $merchant->id)->where('status', 'locked')->get();
        $this->assertSame(2, $locked->count()); // 2 笔被锁
        $available = SubsiteLedgerEntry::where('merchant_id', $merchant->id)->where('status', 'available')->sum('amount');
        $this->assertSame(200, (int) $available); // 剩 200
    }

    public function test_cannot_withdraw_more_than_available(): void
    {
        $user = User::factory()->create();
        $merchant = Merchant::create(['user_id' => $user->id, 'name' => 'Sub', 'slug' => 's2', 'status' => 1, 'commission_rate' => 0, 'settings' => ['is_subsite' => true]]);
        SubsiteLedgerEntry::create(['merchant_id' => $merchant->id, 'type' => 'order_profit', 'amount' => 100, 'status' => 'available', 'idempotency_key' => 'k1', 'available_at' => now()]);

        $this->expectException(\RuntimeException::class);
        \App\Support\SubsiteWithdrawalService::request($merchant->id, 500, 'alipay', 'a@b.com', 'T'); // 只有 100 可用
    }
}
```

- [ ] **Step 2: SubsiteWithdrawalService（FIFO + 实时 SUM 校验）**

`app/Support/SubsiteWithdrawalService.php`:
```php
<?php

namespace App\Support;

use App\Models\SubsiteLedgerEntry;
use App\Models\Withdrawal;
use Illuminate\Support\Facades\DB;

/**
 * 分站 FIFO 提现(spec §7.3):实时 SUM(available) 校验,FIFO 消费 available 条目。
 * 部分提现拆分行保留审计(参考 dujiao-next)。不信任缓存余额。
 */
class SubsiteWithdrawalService
{
    public static function request(int $merchantId, int $amountFen, string $method, string $account, string $accountName): Withdrawal
    {
        return DB::transaction(function () use ($merchantId, $amountFen, $method, $account, $accountName) {
            $merchant = \App\Models\Merchant::lockForUpdate()->findOrFail($merchantId);

            // 实时 SUM(available) 校验(不信任缓存)
            $availableSum = SubsiteLedgerEntry::where('merchant_id', $merchantId)
                ->where('status', 'available')->sum('amount');
            if ($amountFen > $availableSum) {
                throw new \RuntimeException('可提现金额不足');
            }

            // FIFO 消费:按 available_at 升序锁定
            $remaining = $amountFen;
            $lockedIds = [];
            $entries = SubsiteLedgerEntry::where('merchant_id', $merchantId)
                ->where('status', 'available')
                ->whereNull('withdraw_request_id')
                ->orderBy('available_at')->orderBy('id')
                ->lockForUpdate()->get();

            foreach ($entries as $entry) {
                if ($remaining <= 0) break;
                if ($entry->amount <= $remaining) {
                    // 整笔消费
                    $entry->update(['status' => 'locked']);
                    $lockedIds[] = $entry->id;
                    $remaining -= $entry->amount;
                } else {
                    // 拆分:原行缩为 remaining,新行存差额
                    $leftover = $entry->amount - $remaining;
                    SubsiteLedgerEntry::create([
                        'merchant_id' => $merchantId, 'order_id' => $entry->order_id,
                        'type' => $entry->type, 'amount' => $leftover, 'status' => 'available',
                        'available_at' => $entry->available_at, 'idempotency_key' => 'split:' . $entry->id . ':' . uniqid(),
                    ]);
                    $entry->update(['amount' => $remaining, 'status' => 'locked']);
                    $lockedIds[] = $entry->id;
                    $remaining = 0;
                }
            }

            // 创建提现记录(复用 withdrawals 表)
            $withdrawal = Withdrawal::create([
                'user_id' => $merchant->user_id, 'amount' => $amountFen, 'actual_amount' => $amountFen,
                'fee' => 0, 'method' => $method, 'account' => $account, 'account_name' => $accountName,
                'status' => Withdrawal::STATUS_PENDING,
            ]);

            // 关联 locked 条目到提现单
            SubsiteLedgerEntry::whereIn('id', $lockedIds)->update(['withdraw_request_id' => $withdrawal->id]);

            return $withdrawal;
        });
    }

    /** 审批通过:ledger → withdrawn */
    public static function approve(int $withdrawalId): void
    {
        DB::transaction(function () use ($withdrawalId) {
            $w = Withdrawal::findOrFail($withdrawalId);
            $w->update(['status' => Withdrawal::STATUS_APPROVED]);
            SubsiteLedgerEntry::where('withdraw_request_id', $withdrawalId)->update(['status' => 'withdrawn']);
        });
    }

    /** 驳回:ledger 退回 available */
    public static function reject(int $withdrawalId, string $reason): void
    {
        DB::transaction(function () use ($withdrawalId, $reason) {
            $w = Withdrawal::findOrFail($withdrawalId);
            $w->update(['status' => Withdrawal::STATUS_REJECTED, 'reject_reason' => $reason]);
            SubsiteLedgerEntry::where('withdraw_request_id', $withdrawalId)
                ->update(['status' => 'available', 'withdraw_request_id' => null]);
        });
    }
}
```

- [ ] **Step 3: 提现路由（控制台组内）**

在 `routes/api.php` 的 `subsite-console` 组内加：
```php
        Route::post('/withdrawals', [SubsiteConsoleController::class, 'requestWithdrawal']);
```
在 SubsiteConsoleController 加方法：
```php
    public function requestWithdrawal(Request $request): JsonResponse
    {
        $merchant = $this->getMySubsite($request);
        if (! $merchant) return response()->json(['message' => '无分站'], 404);
        $data = $request->validate([
            'amount' => 'required|numeric|min:0.01',
            'method' => 'required|in:alipay,wechat,usdt',
            'account' => 'required|string|max:200',
            'account_name' => 'required|string|max:50',
        ]);
        try {
            $w = \App\Support\SubsiteWithdrawalService::request(
                $merchant->id, (int) round($data['amount'] * 100), $data['method'], $data['account'], $data['account_name']
            );
            return response()->json($w, 201);
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 400);
        }
    }
```

- [ ] **Step 4: 跑测试 + Commit**

```bash
docker exec zcard-laravel.test-1 php artisan test tests/Feature/SubsiteWithdrawalTest.php
docker exec zcard-laravel.test-1 php artisan test
git add app/Support/SubsiteWithdrawalService.php app/Http/Controllers/Api/SubsiteConsoleController.php routes/api.php tests/Feature/SubsiteWithdrawalTest.php
git commit -m "feat: subsite FIFO withdrawal service (consume ledger + split + approve/reject)"
```

---

## Task 3: 白标展示（StorefrontSettings 端点返回分站配置）

**Files:**
- Modify: `app/Http/Controllers/Api/StorefrontSettingsController.php`

- [ ] **Step 1: 改 StorefrontSettingsController::show 返回分站白标**

读 `app/Http/Controllers/Api/StorefrontSettingsController.php`。在返回的配置里，若当前是分站，合并分站的 site_name/logo/announcement（覆盖主站值）：
```php
    public function show()
    {
        $config = StorefrontConfig::all();
        $subsite = request()->attributes->get('subsite');
        if ($subsite && ($subsite->settings['is_subsite'] ?? false)) {
            // 白标:分站的 site_name/logo/announcement 覆盖主站
            $config['site_name'] = $subsite->settings['site_name'] ?? $config['site_name'];
            $config['site_logo'] = $subsite->settings['logo'] ?? ($config['site_logo'] ?? '');
            $config['site_notice'] = $subsite->settings['announcement'] ?? ($config['site_notice'] ?? '');
        }
        return response()->json($config);
    }
```
（读现有 show 方法确认返回结构，按实际调整。）

- [ ] **Step 2: Commit**

```bash
git add app/Http/Controllers/Api/StorefrontSettingsController.php
git commit -m "feat: subsite white-label in StorefrontSettings (site name/logo/notice override)"
```

---

## Task 4: 前端 storefront（白标 + 分站主入口）— 可选/简化

> 说明:前端完整控制台(我的分站页/域名管理/商品配置/提现)工作量较大,本 Task 列为后续。v1 可先靠后台(sysadmin)管理分站,前台白标已由 Task 3 后端支持(站点配置端点自动返回分站配置,storefront 现有渲染 site_name/logo 逻辑无需改即可生效)。

- [ ] **Step 1: 验证白标生效**

storefront 已读 `settings.config.site_name/site_logo` 渲染（AppHeader/AppFooter）。Task 3 后端在分站域名访问时返回分站的这些值，所以**前台无需改动即可白标**。验证：
```bash
# 配一个分站 + 域名,访问该域名,/api/settings/storefront 返回分站 site_name
```

- [ ] **Step 2: (后续)前台"我的分站"页**

若需前台自助:新建 storefront/src/views/MySubsite.vue(调 /api/subsite-console/*),路由 /my-subsite(requiresAuth + requiresMainSite)。本计划列为后续。

---

## Self-Review
**Spec 覆盖**: §8.2 控制台只在主站→Task1; §8.2 自助 API(finance/ledger/domains/products)→Task1; §7.3 FIFO 提现→Task2; §8.3 白标→Task3; §8.1 后台管理(Phase1 Task6 已覆盖)。Phase3 全覆盖(前台控制台页列为后续可选)。
**类型一致**: SubsiteLedgerEntry 字段在 Phase2 定义、Task2 消费一致; idempotency_key 'split:' 前缀在 Task2; Withdrawal 表复用(STATUS_PENDING/APPROVED/REJECTED)一致。
