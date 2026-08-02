# 货源对接 Phase 2（对外供货 API + 供货账号管理）实施计划

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** 实现 ZCard「作为上游」能力：对外供货 API（`/api/supply/*`）+ HMAC 鉴权中间件（含防重放）+ 供货账号管理后台（创建/重置/充值/账本/SKU 级专属定价两个入口）+ SupplyOrderService（供货下单扣预存并发卡）+ 多语言文案 + OrderPaid 事件守卫。完成后，外部系统（含另一个 ZCard）可通过 API 注册对接拿货。

**Architecture:** 供货下单复用现有卡库存逻辑（lockForUpdate 防超卖），但创建独立 supply_orders 记录下游幂等键。鉴权用 Phase 1 的 HmacSigner + 四头中间件。账号管理走 `/api/admin/supplier-accounts/*`（复用 admin.role 中间件）。事件守卫防止 supply 订单误触发分销/分站结算。

**Tech Stack:** Laravel 13.8, PHP 8.3, PHPUnit 12, Guzzle。

**测试策略:** TDD。HMAC 中间件、账号 CRUD、充值账本幂等、下单扣费发卡、专属价查找优先级、事件守卫——均有 Feature 测试。

**依赖:** Phase 1（数据模型 + HmacSigner + SupplyManager 骨架）已完成。

**Spec:** `docs/superpowers/specs/2026-08-02-zcard-supply-integration-design.md`（§4 对外供货 API、§4.5.1 事件守卫、§7 账号管理、§8.1 多语言、§8.5 安全）

---

## Task 1: 多语言文案（messages.php）

**Files:**
- Modify: `lang/zh_CN/messages.php`
- Modify: `lang/en/messages.php`

- [ ] **Step 1: 中文文案**

读取 `lang/zh_CN/messages.php`，在返回数组末尾（最后一个键之前）追加：
```php

    // ===== 货源对接(spec §8.1) =====
    'supply.driver_dujiao_next' => '独角数卡(dujiao-next)',
    'supply.driver_acg_faka' => 'ACG发卡',
    'supply.driver_zcard' => 'ZCard',
    'supply.field_base_url' => '站点地址',
    'supply.field_api_key' => 'API Key',
    'supply.field_api_secret' => 'API Secret',
    'supply.field_app_id' => 'App ID',
    'supply.field_app_key' => 'App Key',
    'supply.stock_mode_realtime' => '实时查询',
    'supply.stock_mode_realtime_help' => '顾客在前台看到的库存是当下向上游发起一次实时查询的结果。最准确,不会超卖(下单前现查);前台每次访问多一次对上游请求,依赖上游响应速度。',
    'supply.stock_mode_synced' => '本地缓存同步',
    'supply.stock_mode_synced_help' => '定时将上游库存数量拷贝一份到本地,前台读本地缓存。前台快;有超卖风险(同步间隔内上游可能已售罄),下单时会再查一次上游兜底。',
    'supply.failure_manual' => '人工介入',
    'supply.failure_auto_refund' => '自动退款',
    'supply.pricing_fixed_markup' => '按固定加价',
    'supply.pricing_percent_markup' => '按比例加价',
    'supply.pricing_equal_cost' => '平价',
    'supply.pricing_pending' => '留空待定',
    'supply.secret_show_once_warning' => '请立即复制保存 API Secret,关闭后将无法再次查看',
    'supply.balance_low_warning' => '预存余额不足',

    // 供货 API 错误(spec §4.6)
    'supply_api.insufficient_balance' => '余额不足',
    'supply_api.insufficient_stock' => '库存不足',
    'supply_api.product_unavailable' => '商品不可用',
    'supply_api.order_not_cancelable' => '订单不可取消',
    'supply_api.bad_request' => '请求参数错误',
    'supply_api.timestamp_expired' => '请求已过期',
    'supply_api.invalid_signature' => '签名错误',
    'supply_api.nonce_reused' => '请求不可重复',
    'supply_api.unauthorized' => '未认证',
```

- [ ] **Step 2: 英文文案**

读取 `lang/en/messages.php`，追加对应英文：
```php

    // ===== Supply Integration (spec §8.1) =====
    'supply.driver_dujiao_next' => 'Dujiao-next',
    'supply.driver_acg_faka' => 'ACG Faka',
    'supply.driver_zcard' => 'ZCard',
    'supply.field_base_url' => 'Site URL',
    'supply.field_api_key' => 'API Key',
    'supply.field_api_secret' => 'API Secret',
    'supply.field_app_id' => 'App ID',
    'supply.field_app_key' => 'App Key',
    'supply.stock_mode_realtime' => 'Realtime query',
    'supply.stock_mode_realtime_help' => 'Stock shown to customers is queried from upstream in real time. Most accurate, no oversell; adds one upstream request per storefront view.',
    'supply.stock_mode_synced' => 'Local cached sync',
    'supply.stock_mode_synced_help' => 'Upstream stock is periodically copied to local cache. Faster; has oversell risk (upstream may sell out between syncs), re-checked at order time as fallback.',
    'supply.failure_manual' => 'Manual intervention',
    'supply.failure_auto_refund' => 'Auto refund',
    'supply.pricing_fixed_markup' => 'Fixed markup',
    'supply.pricing_percent_markup' => 'Percent markup',
    'supply.pricing_equal_cost' => 'At cost',
    'supply.pricing_pending' => 'Leave blank',
    'supply.secret_show_once_warning' => 'Copy and save the API Secret now. It cannot be viewed again after closing.',
    'supply.balance_low_warning' => 'Low prepaid balance',

    'supply_api.insufficient_balance' => 'Insufficient balance',
    'supply_api.insufficient_stock' => 'Insufficient stock',
    'supply_api.product_unavailable' => 'Product unavailable',
    'supply_api.order_not_cancelable' => 'Order cannot be canceled',
    'supply_api.bad_request' => 'Bad request',
    'supply_api.timestamp_expired' => 'Request expired',
    'supply_api.invalid_signature' => 'Invalid signature',
    'supply_api.nonce_reused' => 'Nonce already used',
    'supply_api.unauthorized' => 'Unauthorized',
```

- [ ] **Step 3: 验证翻译可读**

运行：
```bash
php artisan config:clear && php -r "require 'vendor/autoload.php'; \$app = require 'bootstrap/app.php'; \$app->boot(); app('translator')->setLocale('zh_CN'); echo __('messages.supply_api.insufficient_balance') . PHP_EOL; app('translator')->setLocale('en'); echo __('messages.supply_api.insufficient_balance') . PHP_EOL;"
```
预期：`余额不足` / `Insufficient balance`。

- [ ] **Step 4: 提交**

```bash
git add lang/zh_CN/messages.php lang/en/messages.php
git commit -m "feat(supply): 货源对接多语言文案(zh_CN/en)"
```

---

## Task 2: NonceStore（防重放存储抽象）

**Files:**
- Create: `app/Supply/NonceStore.php`
- Create: `tests/Feature/NonceStoreTest.php`

按 `config('zcard.supply.nonce_store')` 选 redis/cache/database 后端。统一接口 `remember(string $nonce, int $ttl): bool`（未见则存并返回 true，已见返回 false）。

- [ ] **Step 1: 写失败测试**

`tests/Feature/NonceStoreTest.php`:
```php
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
```

- [ ] **Step 2: 运行测试确认失败**

运行：
```bash
php artisan test --filter=NonceStoreTest
```
预期：FAIL（类不存在）。

- [ ] **Step 3: 实现 NonceStore**

`app/Supply/NonceStore.php`:
```php
<?php

namespace App\Supply;

use App\Models\SupplyNonce;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;

/**
 * 防重放 nonce 存储(spec §8.5)
 * 按 config('zcard.supply.nonce_store') 选后端:redis|cache|database。
 * remember():未见则记录并返回 true;已见返回 false(拒绝重放)。
 */
class NonceStore
{
    public function remember(string $nonce, int $ttlSeconds): bool
    {
        return match (config('zcard.supply.nonce_store', 'cache')) {
            'redis' => $this->rememberRedis($nonce, $ttlSeconds),
            'database' => $this->rememberDatabase($nonce, $ttlSeconds),
            default => $this->rememberCache($nonce, $ttlSeconds),
        };
    }

    /** 清理已过期的 database nonce(调度任务调用) */
    public function pruneExpiredDatabase(): void
    {
        SupplyNonce::where('expires_at', '<', now())->delete();
    }

    private function rememberCache(string $nonce, int $ttl): bool
    {
        $key = "supply:nonce:{$nonce}";
        // lockForUpdate 语义:cache add 原子写入,已存在返回 false
        return Cache::add($key, 1, $ttl);
    }

    private function rememberRedis(string $nonce, int $ttl): bool
    {
        try {
            // SET NX 原子操作:键不存在才设置
            return (bool) Redis::connection()->set("supply:nonce:{$nonce}", 1, 'EX', $ttl, 'NX');
        } catch (\Throwable) {
            // Redis 不可用时回退到 cache
            return $this->rememberCache($nonce, $ttl);
        }
    }

    private function rememberDatabase(string $nonce, int $ttl): bool
    {
        try {
            SupplyNonce::create([
                'nonce' => $nonce,
                'expires_at' => now()->addSeconds($ttl),
            ]);
            return true;
        } catch (\Illuminate\Database\QueryException $e) {
            // 唯一约束冲突 = 已存在 = 重放
            if (str_contains($e->getMessage(), 'Duplicate') || str_contains($e->getMessage(), 'unique')) {
                return false;
            }
            throw $e;
        }
    }
}
```

- [ ] **Step 4: 运行测试确认通过**

运行：
```bash
php artisan test --filter=NonceStoreTest
```
预期：2 个测试通过。

- [ ] **Step 5: 注册清理调度命令（可选，Phase 3 接入 schedule）**

本步先不动 schedule；database 模式下的清理放 Phase 3 Task。提交当前实现：
```bash
git add app/Supply/NonceStore.php tests/Feature/NonceStoreTest.php
git commit -m "feat(supply): NonceStore防重放存储(redis/cache/database三后端)"
```

---

## Task 3: HMAC 鉴权中间件

**Files:**
- Create: `app/Http/Middleware/SupplyAuth.php`
- Create: `tests/Feature/SupplyAuthMiddlewareTest.php`
- Modify: `bootstrap/app.php`

四头鉴权（spec §4.2）：X-Supply-Key/Timestamp/Nonce/Signature。验签后注入 supplier_account 到请求。

- [ ] **Step 1: 写失败测试**

`tests/Feature/SupplyAuthMiddlewareTest.php`:
```php
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
        // 注意:测试里 api_secret 存明文(不走加密),与生产服务层不同。这里直接用明文验签。
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

        $headers = $this->signedHeaders($account, 'POST', '/api/supply/ping', '');
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
```

> 说明：测试用明文 api_secret 存入（不走服务层加密），通过 `getRawOriginal('api_secret')` 取明文算签名。中间件验签时也读 `getRawOriginal`（见 Step 3）。生产中 SupplyAccountService 写入时先 `Crypt::encryptString`，中间件读 raw 后先解密——为保持简单，中间件统一处理（见下）。

- [ ] **Step 2: 运行测试确认失败**

运行：
```bash
php artisan test --filter=SupplyAuthMiddlewareTest
```
预期：FAIL（路由/中间件不存在）。

- [ ] **Step 3: 实现 SupplyAuth 中间件**

`app/Http/Middleware/SupplyAuth.php`:
```php
<?php

namespace App\Http\Middleware;

use App\Models\SupplierAccount;
use App\Supply\HmacSigner;
use App\Supply\NonceStore;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;

/**
 * 供货 API HMAC 鉴权中间件(spec §4.2)
 * 四头:X-Supply-Key/Timestamp/Nonce/Signature。
 * 流程:查账号→校验状态→timestamp窗口→nonce防重放→验签→注入supplier_account。
 */
class SupplyAuth
{
    public function __construct(private readonly NonceStore $nonceStore) {}

    public function handle(Request $request, Closure $next): mixed
    {
        if (! config('zcard.features.supply')) {
            return response()->json(['ok' => false, 'error_code' => 'unauthorized', 'message' => __('messages.supply_api.unauthorized')], 401);
        }

        $apiKey = $request->header('X-Supply-Key');
        $timestamp = $request->header('X-Supply-Timestamp');
        $nonce = $request->header('X-Supply-Nonce');
        $signature = $request->header('X-Supply-Signature');

        if (! $apiKey || ! $timestamp || ! $nonce || ! $signature) {
            return $this->fail('unauthorized', 401);
        }

        $account = SupplierAccount::where('api_key', $apiKey)->first();
        if (! $account || ! $account->isActive()) {
            return $this->fail('unauthorized', 401);
        }

        // timestamp 窗口
        $skew = (int) config('zcard.supply.timestamp_skew', 300);
        if (! HmacSigner::timestampValid((int) $timestamp, $skew)) {
            return $this->fail('timestamp_expired', 401);
        }

        // nonce 防重放
        if (! $this->nonceStore->remember($nonce, $skew)) {
            return $this->fail('nonce_reused', 401);
        }

        // 解密 secret(生产加密;测试明文 Crypt::decrypt 失败则当明文用)
        $rawSecret = $account->getRawOriginal('api_secret');
        try {
            $secret = Crypt::decryptString($rawSecret);
        } catch (\Throwable) {
            $secret = $rawSecret; // 未加密(测试或旧数据)
        }

        // 验签:PATH 不含 query
        $path = $request->getPathInfo();
        $bodyMd5 = md5($request->getContent() ?: '');
        $signString = HmacSigner::buildSignString($request->method(), $path, $timestamp, $nonce, $bodyMd5);

        if (! HmacSigner::verify($secret, $signString, $signature)) {
            return $this->fail('invalid_signature', 401);
        }

        $request->attributes->set('supplier_account', $account);
        return $next($request);
    }

    private function fail(string $errorCode, int $status): \Illuminate\Http\JsonResponse
    {
        return response()->json([
            'ok' => false,
            'error_code' => $errorCode,
            'message' => __('messages.supply_api.' . $errorCode),
        ], $status);
    }
}
```

- [ ] **Step 4: 注册中间件别名**

读取 `bootstrap/app.php`，在 `$middleware->alias([...])` 数组里追加：
```php
            'supply.auth' => \App\Http\Middleware\SupplyAuth::class,
```

- [ ] **Step 5: 提交**

```bash
git add app/Http/Middleware/SupplyAuth.php tests/Feature/SupplyAuthMiddlewareTest.php bootstrap/app.php
git commit -m "feat(supply): HMAC四头鉴权中间件(key/timestamp/nonce/signature+防重放)"
```

---

## Task 4: 供货 API 控制器骨架 + 路由 + ping 端点

**Files:**
- Create: `app/Http/Controllers/Api/Supply/SupplyController.php`
- Create: `app/Http/Controllers/Api/Supply/SupplyProductController.php`
- Create: `app/Http/Controllers/Api/Supply/SupplyOrderController.php`
- Modify: `routes/api.php`

先建控制器骨架 + ping 端点（让 Task 3 测试通过），商品/订单端点留后续 Task。

- [ ] **Step 1: 主控制器（ping）**

`app/Http/Controllers/Api/Supply/SupplyController.php`:
```php
<?php

namespace App\Http\Controllers\Api\Supply;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * 供货 API 主控制器(spec §4.3)
 * /api/supply/*  对外供货,被下游系统(含另一个ZCard)调用。
 */
class SupplyController extends Controller
{
    /** POST /api/supply/ping 测连通+返回余额 */
    public function ping(Request $request): JsonResponse
    {
        $account = $request->attributes->get('supplier_account');

        return response()->json([
            'ok' => true,
            'name' => $account->name,
            'balance' => (int) $account->balance,
            'currency' => config('app.currency', 'CNY'),
        ]);
    }

    /** POST /api/supply/callback 接收上游异步回调(本站作为下游时,Phase 3 实现) */
    public function callback(Request $request): JsonResponse
    {
        // Phase 3 SupplyOrderController 实现
        return response()->json(['ok' => true]);
    }
}
```

- [ ] **Step 2: 商品控制器（骨架）**

`app/Http/Controllers/Api/Supply/SupplyProductController.php`:
```php
<?php

namespace App\Http\Controllers\Api\Supply;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * 供货 API 商品控制器(spec §4.3) —— 下游查询商品/库存
 * 价格按当前鉴权账号的专属价注入(spec §4.5)。
 */
class SupplyProductController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        // Task 5 实现
        return response()->json(['ok' => true, 'items' => []]);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        return response()->json(['ok' => true]);
    }

    public function stock(Request $request, int $id): JsonResponse
    {
        return response()->json(['ok' => true]);
    }
}
```

- [ ] **Step 3: 订单控制器（骨架）**

`app/Http/Controllers/Api/Supply/SupplyOrderController.php`:
```php
<?php

namespace App\Http\Controllers\Api\Supply;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * 供货 API 订单控制器(spec §4.4) —— 下游下单拿货
 */
class SupplyOrderController extends Controller
{
    public function create(Request $request): JsonResponse
    {
        // Task 7 实现
        return response()->json(['ok' => true]);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        return response()->json(['ok' => true]);
    }

    public function cancel(Request $request, int $id): JsonResponse
    {
        return response()->json(['ok' => true]);
    }
}
```

- [ ] **Step 4: 注册路由**

读取 `routes/api.php`，在文件末尾（admin 路由组之后）追加：
```php

// ===== 供货 API(对外供货,spec §4.3) =====
Route::prefix('supply')->middleware(['supply.auth', 'throttle:' . config('zcard.supply.rate_limit', 60) . ',1'])
    ->group(function () {
        Route::post('ping', [SupplyController::class, 'ping'])->name('api.supply.ping');
        Route::get('categories', [SupplyProductController::class, 'categories'])->name('api.supply.categories');
        Route::get('products', [SupplyProductController::class, 'index'])->name('api.supply.products.index');
        Route::get('products/{id}', [SupplyProductController::class, 'show'])->name('api.supply.products.show')
            ->whereNumber('id');
        Route::get('products/{id}/stock', [SupplyProductController::class, 'stock'])->name('api.supply.products.stock')
            ->whereNumber('id');
        Route::post('orders', [SupplyOrderController::class, 'create'])->name('api.supply.orders.create');
        Route::get('orders/{id}', [SupplyOrderController::class, 'show'])->name('api.supply.orders.show')
            ->whereNumber('id');
        Route::post('orders/{id}/cancel', [SupplyOrderController::class, 'cancel'])->name('api.supply.orders.cancel')
            ->whereNumber('id');
    });
// 回调端点不经过 supply.auth(上游用各自协议签名,由驱动 verifyCallback 处理)
Route::post('supply/callback', [SupplyController::class, 'callback'])->name('api.supply.callback');
```

并在 `routes/api.php` 顶部 use 区追加控制器导入：
```php
use App\Http\Controllers\Api\Supply\SupplyController;
use App\Http\Controllers\Api\Supply\SupplyProductController;
use App\Http\Controllers\Api\Supply\SupplyOrderController;
```

同时在 `SupplyProductController` 加 `categories` 方法骨架（路由引用了它）：
```php
    public function categories(Request $request): JsonResponse
    {
        return response()->json(['ok' => true, 'categories' => []]);
    }
```

- [ ] **Step 5: 运行中间件测试确认通过**

运行：
```bash
php artisan test --filter=SupplyAuthMiddlewareTest
```
预期：5 个测试通过。

- [ ] **Step 6: 提交**

```bash
git add app/Http/Controllers/Api/Supply/ routes/api.php
git commit -m "feat(supply): 供货API控制器骨架+路由组+ping端点"
```

---

## Task 5: 专属价查找服务（SupplyPricingService）

**Files:**
- Create: `app/Supply/SupplyPricingService.php`
- Create: `tests/Feature/SupplyPricingServiceTest.php`

spec §7.4 优先级：SKU级 → 商品级 → factory_price 兜底。

- [ ] **Step 1: 写失败测试**

`tests/Feature/SupplyPricingServiceTest.php`:
```php
<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductSku;
use App\Models\SupplierAccount;
use App\Models\SupplierProductPrice;
use App\Supply\SupplyPricingService;
use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

class SupplyPricingServiceTest extends TestCase
{
    use RefreshDatabase;

    private function makeProduct(int $factoryPrice = 500): Product
    {
        return Product::create([
            'merchant_id' => 1, 'name' => 'P', 'slug' => 'p' . uniqid(),
            'price' => 800, 'factory_price' => $factoryPrice, 'stock_type' => 'card',
            'status' => 1,
        ]);
    }

    public function test_falls_back_to_factory_price_when_no_special_price(): void
    {
        $product = $this->makeProduct(500);
        $account = SupplierAccount::create(['name' => 'A', 'api_key' => 'k', 'api_secret' => 's']);

        $price = app(SupplyPricingService::class)->resolvePrice($account, $product, null);
        $this->assertSame(500, $price);
    }

    public function test_uses_product_level_price(): void
    {
        $product = $this->makeProduct(500);
        $account = SupplierAccount::create(['name' => 'A', 'api_key' => 'k', 'api_secret' => 's']);
        SupplierProductPrice::create([
            'supplier_account_id' => $account->id, 'product_id' => $product->id,
            'sku_id' => null, 'price' => 450,
        ]);

        $price = app(SupplyPricingService::class)->resolvePrice($account, $product, null);
        $this->assertSame(450, $price);
    }

    public function test_sku_level_overrides_product_level(): void
    {
        $product = $this->makeProduct(500);
        $sku = ProductSku::create([
            'product_id' => $product->id, 'name' => '规格A', 'price' => 600, 'stock_type' => 'card', 'status' => 1,
        ]);
        $account = SupplierAccount::create(['name' => 'A', 'api_key' => 'k', 'api_secret' => 's']);
        SupplierProductPrice::create([
            'supplier_account_id' => $account->id, 'product_id' => $product->id,
            'sku_id' => null, 'price' => 450, // 商品级
        ]);
        SupplierProductPrice::create([
            'supplier_account_id' => $account->id, 'product_id' => $product->id,
            'sku_id' => $sku->id, 'price' => 400, // SKU级(更优)
        ]);

        $price = app(SupplyPricingService::class)->resolvePrice($account, $product, $sku);
        $this->assertSame(400, $price);
    }
}
```

- [ ] **Step 2: 运行测试确认失败**

运行：
```bash
php artisan test --filter=SupplyPricingServiceTest
```
预期：FAIL。

- [ ] **Step 3: 实现 SupplyPricingService**

`app/Supply/SupplyPricingService.php`:
```php
<?php

namespace App\Supply;

use App\Models\Product;
use App\Models\ProductSku;
use App\Models\SupplierAccount;
use App\Models\SupplierProductPrice;

/**
 * 供货专属价查找(spec §7.4)
 * 优先级:SKU级专属价 → 商品级默认价 → factory_price 兜底。
 */
class SupplyPricingService
{
    public function resolvePrice(SupplierAccount $account, Product $product, ?ProductSku $sku): int
    {
        // 1. SKU 级
        if ($sku) {
            $skuPrice = SupplierProductPrice::where('supplier_account_id', $account->id)
                ->where('product_id', $product->id)
                ->where('sku_id', $sku->id)
                ->value('price');
            if ($skuPrice !== null) {
                return (int) $skuPrice;
            }
        }

        // 2. 商品级
        $productPrice = SupplierProductPrice::where('supplier_account_id', $account->id)
            ->where('product_id', $product->id)
            ->whereNull('sku_id')
            ->value('price');
        if ($productPrice !== null) {
            return (int) $productPrice;
        }

        // 3. factory_price 兜底
        return (int) $product->factory_price;
    }
}
```

- [ ] **Step 4: 运行测试确认通过**

运行：
```bash
php artisan test --filter=SupplyPricingServiceTest
```
预期：3 个测试通过。

- [ ] **Step 5: 提交**

```bash
git add app/Supply/SupplyPricingService.php tests/Feature/SupplyPricingServiceTest.php
git commit -m "feat(supply): 专属价查找服务(SKU级→商品级→factory_price兜底)"
```

---

## Task 6: 商品端点实现（index/show/stock + 专属价注入）

**Files:**
- Modify: `app/Http/Controllers/Api/Supply/SupplyProductController.php`

- [ ] **Step 1: 实现 index（商品列表，按账号专属价）**

替换 `SupplyProductController` 的 `index` 方法：
```php
    public function index(Request $request): JsonResponse
    {
        $account = $request->attributes->get('supplier_account');
        $pricing = app(\App\Supply\SupplyPricingService::class);

        $products = \App\Models\Product::query()
            ->where('status', 1)
            ->whereNull('hide') // 或 where('hide', false),按实际列定义
            ->when(! $request->boolean('include_inactive'), fn ($q) => $q->where('status', 1))
            ->with(['skus' => fn ($q) => $q->where('status', 1)])
            ->orderByDesc('id')
            ->paginate($request->integer('page_size', 50));

        return response()->json([
            'ok' => true,
            'items' => $products->getCollection()->map(function (Product $p) use ($account, $pricing) {
                return $this->transformProduct($p, $account, $pricing);
            })->values(),
            'total' => $products->total(),
            'page' => $products->currentPage(),
            'page_size' => $products->perPage(),
        ]);
    }
```

> 注意 `hide` 字段：读取 products 表迁移确认列名。若 `hide` 是 boolean 列，用 `where('hide', false)`。先在该方法顶部用 `$p->where('hide', false)`——实施时核对 `database/migrations/2026_07_30_012308_*` 里 hide 的确切定义（可能是 tinyInteger 或 boolean）。在 controller 顶部加 `use App\Models\Product;`。

- [ ] **Step 2: 实现 transformProduct 辅助方法**

在 `SupplyProductController` 加私有方法：
```php
    private function transformProduct(Product $p, $account, $pricing): array
    {
        return [
            'id' => $p->id,
            'name' => $p->name,
            'slug' => $p->slug,
            'description' => $p->description,
            'cover' => $p->cover,
            'price' => $pricing->resolvePrice($account, $p, null), // 分
            'category_id' => $p->category_id,
            'skus' => $p->skus->map(function ($sku) use ($account, $p, $pricing) {
                return [
                    'id' => $sku->id,
                    'name' => $sku->name,
                    'price' => $pricing->resolvePrice($account, $p, $sku),
                ];
            }),
        ];
    }
```

- [ ] **Step 3: 实现 show（单商品）**

替换 `show` 方法：
```php
    public function show(Request $request, int $id): JsonResponse
    {
        $account = $request->attributes->get('supplier_account');
        $pricing = app(\App\Supply\SupplyPricingService::class);
        $product = Product::with(['skus' => fn ($q) => $q->where('status', 1)])->find($id);

        if (! $product || $product->status != 1) {
            return response()->json(['ok' => false, 'error_code' => 'product_unavailable', 'message' => __('messages.supply_api.product_unavailable')], 404);
        }

        return response()->json([
            'ok' => true,
            'product' => $this->transformProduct($product, $account, $pricing),
        ]);
    }
```

- [ ] **Step 4: 实现 stock（实时库存）**

替换 `stock` 方法：
```php
    public function stock(Request $request, int $id): JsonResponse
    {
        $product = Product::find($id);
        if (! $product) {
            return response()->json(['ok' => false, 'error_code' => 'product_unavailable'], 404);
        }

        return response()->json([
            'ok' => true,
            'product_id' => $id,
            'stock' => $product->availableStock(),
        ]);
    }
```

> `availableStock()` 已存在于 Product 模型（Phase 0 实现，= cards where status=unused 的 count）。

- [ ] **Step 5: 实现 categories**

替换 `categories` 方法：
```php
    public function categories(Request $request): JsonResponse
    {
        $categories = \App\Models\Category::where('status', 1)->orderBy('sort')->get(['id', 'parent_id', 'name', 'slug', 'icon']);

        return response()->json([
            'ok' => true,
            'categories' => $categories,
        ]);
    }
```

- [ ] **Step 6: 手动验证（curl 或测试）**

写一个集成测试 `tests/Feature/SupplyProductApiTest.php` 验证商品列表带专属价：
```php
<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\SupplierAccount;
use App\Models\SupplierProductPrice;
use App\Supply\HmacSigner;
use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

class SupplyProductApiTest extends TestCase
{
    use RefreshDatabase;

    private function signedPost(SupplierAccount $a, string $path): array
    {
        $ts = (string) time();
        $nonce = 'n' . uniqid();
        $ss = HmacSigner::buildSignString('GET', $path, $ts, $nonce, md5(''));
        return [
            'X-Supply-Key' => $a->api_key, 'X-Supply-Timestamp' => $ts,
            'X-Supply-Nonce' => $nonce, 'X-Supply-Signature' => HmacSigner::sign($a->getRawOriginal('api_secret'), $ss),
        ];
    }

    public function test_products_return_special_price_for_account(): void
    {
        config(['zcard.features.supply' => true, 'zcard.supply.nonce_store' => 'cache']);
        $account = SupplierAccount::create(['name' => 'A', 'api_key' => 'ak', 'api_secret' => 'sk', 'status' => 'active']);
        $product = Product::create([
            'merchant_id' => 1, 'name' => 'P', 'slug' => 'p1', 'price' => 800,
            'factory_price' => 500, 'stock_type' => 'card', 'status' => 1,
        ]);
        SupplierProductPrice::create([
            'supplier_account_id' => $account->id, 'product_id' => $product->id, 'sku_id' => null, 'price' => 460,
        ]);

        // GET 需要走 supply.auth 中间件(header 验签)
        $resp = $this->withHeaders($this->signedPost($account, '/api/supply/products'))->getJson('/api/supply/products');

        $resp->assertOk();
        $this->assertSame(460, $resp->json('items.0.price'));
    }
}
```

运行：
```bash
php artisan test --filter=SupplyProductApiTest
```
预期：通过。

- [ ] **Step 7: 提交**

```bash
git add app/Http/Controllers/Api/Supply/SupplyProductController.php tests/Feature/SupplyProductApiTest.php
git commit -m "feat(supply): 商品端点(index/show/stock/categories)+专属价注入"
```

---

## Task 7: SupplyOrderService（供货下单扣预存发卡）

**Files:**
- Create: `app/Supply/SupplyOrderService.php`
- Create: `tests/Feature/SupplyOrderServiceTest.php`

核心：复用卡库存 lockForUpdate，扣预存余额，同步/异步发卡，幂等。

- [ ] **Step 1: 写失败测试**

`tests/Feature/SupplyOrderServiceTest.php`:
```php
<?php

namespace Tests\Feature;

use App\Models\Card;
use App\Models\Product;
use App\Models\SupplierAccount;
use App\Models\SupplierLedgerEntry;
use App\Supply\SupplyOrderService;
use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;

class SupplyOrderServiceTest extends TestCase
{
    use RefreshDatabase;

    private function makeAccount(int $balance = 100000): SupplierAccount
    {
        return SupplierAccount::create([
            'name' => 'A', 'api_key' => 'ak', 'api_secret' => Crypt::encryptString('sk'),
            'balance' => $balance, 'status' => 'active',
        ]);
    }

    private function makeProductWithCards(int $price, int $cardCount): Product
    {
        $p = Product::create([
            'merchant_id' => 1, 'name' => 'P', 'slug' => 'p' . uniqid(),
            'price' => $price, 'factory_price' => $price, 'stock_type' => 'card', 'status' => 1,
        ]);
        for ($i = 0; $i < $cardCount; $i++) {
            Card::create([
                'product_id' => $p->id, 'content' => 'card_' . $i, 'status' => Card::STATUS_UNUSED,
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
        $this->assertSame(99000, (int) $account->fresh()->balance); // 只扣一次
    }

    public function test_insufficient_balance_rejected(): void
    {
        $account = $this->makeAccount(300); // 只有 3 分
        $product = $this->makeProductWithCards(500, 3);
        $service = app(SupplyOrderService::class);

        try {
            $service->createOrder($account, ['product_id' => $product->id, 'quantity' => 1, 'downstream_order_no' => 'DOWN-3'], 'sync');
            $this->fail('应抛余额不足异常');
        } catch (\App\Supply\Exceptions\SupplyApiException $e) {
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
        } catch (\App\Supply\Exceptions\SupplyApiException $e) {
            $this->assertSame('insufficient_stock', $e->errorCode);
        }
    }
}
```

- [ ] **Step 2: 建 SupplyApiException**

`app/Supply/Exceptions/SupplyApiException.php`:
```php
<?php

namespace App\Supply\Exceptions;

use RuntimeException;

/**
 * 供货 API 业务异常(携带 error_code 供控制器映射 HTTP 状态码)。
 */
class SupplyApiException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message = '',
        public readonly int $httpStatus = 400,
    ) {
        parent::__construct($message ?: $errorCode);
    }

    public static function insufficientBalance(): self
    {
        return new self('insufficient_balance', __('messages.supply_api.insufficient_balance'), 402);
    }

    public static function insufficientStock(): self
    {
        return new self('insufficient_stock', __('messages.supply_api.insufficient_stock'), 409);
    }

    public static function productUnavailable(): self
    {
        return new self('product_unavailable', __('messages.supply_api.product_unavailable'), 404);
    }
}
```

- [ ] **Step 3: 运行测试确认失败**

运行：
```bash
php artisan test --filter=SupplyOrderServiceTest
```
预期：FAIL。

- [ ] **Step 4: 实现 SupplyOrderService**

`app/Supply/SupplyOrderService.php`:
```php
<?php

namespace App\Supply;

use App\Models\Card;
use App\Models\Order;
use App\Models\Product;
use App\Models\SupplierAccount;
use App\Models\SupplierLedgerEntry;
use App\Models\SupplyOrder;
use App\Supply\Exceptions\SupplyApiException;
use Illuminate\Support\Facades\DB;

/**
 * 供货下单服务(spec §4.4)
 * 复用卡库存 lockForUpdate 防超卖;扣预存余额;写幂等 supply_orders。
 */
class SupplyOrderService
{
    public function __construct(private readonly SupplyPricingService $pricing) {}

    /**
     * @param  array{product_id:int,sku_id?:int,quantity:int,downstream_order_no:string,contact?:string,callback_url?:string}  $params
     * @param  'sync'|'async'  $mode
     * @return array{supply_order_id:int,order_id:int,amount:int,cards:array<int,string>}
     * @throws SupplyApiException
     */
    public function createOrder(SupplierAccount $account, array $params, string $mode = 'sync'): array
    {
        // 幂等:同 downstream_order_no 已存在则返回
        $existing = SupplyOrder::where('supplier_account_id', $account->id)
            ->where('downstream_order_no', $params['downstream_order_no'])
            ->first();
        if ($existing) {
            return $this->formatResult($existing, $account);
        }

        $product = Product::find($params['product_id']);
        if (! $product || $product->status != 1) {
            throw SupplyApiException::productUnavailable();
        }

        $qty = $params['quantity'];
        $unitPrice = $this->pricing->resolvePrice($account, $product, null);
        $amount = $unitPrice * $qty;

        return DB::transaction(function () use ($account, $product, $params, $mode, $qty, $amount) {
            // 锁账号
            $locked = SupplierAccount::where('id', $account->id)->lockForUpdate()->firstOrFail();

            // 余额检查
            if ($locked->balance < $amount) {
                throw SupplyApiException::insufficientBalance();
            }

            // 锁卡(防超卖)
            $cards = Card::where('product_id', $product->id)
                ->where('status', Card::STATUS_UNUSED)
                ->lockForUpdate()
                ->limit($qty)
                ->get();

            if ($cards->count() < $qty) {
                throw SupplyApiException::insufficientStock();
            }

            // 创建本地 order(source=supply,不走支付通道)
            $order = Order::create([
                'order_no' => 'SUP' . date('YmdHis') . random_int(1000, 9999),
                'merchant_id' => 1,
                'product_id' => $product->id,
                'quantity' => $qty,
                'amount' => $amount,
                'cost' => (int) $product->factory_price * $qty,
                'status' => 'paid',
                'delivery_status' => 'delivered',
                'paid_at' => now(),
                'source' => 'supply',
            ]);

            // 同步发卡
            foreach ($cards as $card) {
                $card->update(['status' => Card::STATUS_USED, 'order_id' => $order->id, 'used_at' => now()]);
            }

            // 写 supply_orders
            $supplyOrder = SupplyOrder::create([
                'supplier_account_id' => $account->id,
                'order_id' => $order->id,
                'downstream_order_no' => $params['downstream_order_no'],
                'fulfillment_mode' => $mode,
                'callback_url' => $params['callback_url'] ?? null,
            ]);

            // 扣余额 + 账本
            $locked->decrement('balance', $amount);
            SupplierLedgerEntry::create([
                'supplier_account_id' => $account->id,
                'order_id' => $order->id,
                'type' => SupplierLedgerEntry::TYPE_ORDER,
                'amount' => -$amount,
                'balance_after' => $locked->fresh()->balance,
                'idempotency_key' => "supply_order:{$supplyOrder->id}",
                'remark' => "供货下单[{$params['downstream_order_no']}]",
            ]);

            return [
                'supply_order_id' => $supplyOrder->id,
                'order_id' => $order->id,
                'amount' => $amount,
                'cards' => $cards->pluck('content')->all(),
            ];
        });
    }

    private function formatResult(SupplyOrder $supplyOrder, SupplierAccount $account): array
    {
        $order = $supplyOrder->order;
        $cards = Card::where('order_id', $order->id)->where('status', Card::STATUS_USED)->pluck('content')->all();
        return [
            'supply_order_id' => $supplyOrder->id,
            'order_id' => $order->id,
            'amount' => (int) $order->amount,
            'cards' => $cards,
        ];
    }
}
```

> 注意：`async` 模式第一版与 sync 行为一致（同步发卡）——异步发卡（先扣费不发货、稍后回调）在 Phase 3 接入队列任务时扩展。这里 `mode` 字段记录用途，发卡逻辑暂统一。

- [ ] **Step 5: 运行测试确认通过**

运行：
```bash
php artisan test --filter=SupplyOrderServiceTest
```
预期：4 个测试通过。

- [ ] **Step 6: 提交**

```bash
git add app/Supply/SupplyOrderService.php app/Supply/Exceptions/SupplyApiException.php tests/Feature/SupplyOrderServiceTest.php
git commit -m "feat(supply): SupplyOrderService供货下单(扣预存+发卡+幂等+余额/库存校验)"
```

---

## Task 8: 订单端点实现（create/show/cancel）

**Files:**
- Modify: `app/Http/Controllers/Api/Supply/SupplyOrderController.php`
- Modify: `routes/api.php`（全局异常渲染）

- [ ] **Step 1: 实现 create 端点**

替换 `SupplyOrderController::create`：
```php
    public function create(Request $request): JsonResponse
    {
        $data = $request->validate([
            'product_id' => 'required|integer|exists:products,id',
            'sku_id' => 'nullable|integer|exists:product_skus,id',
            'quantity' => 'required|integer|min:1|max:100',
            'downstream_order_no' => 'required|string|max:100',
            'contact' => 'nullable|string|max:200',
            'callback_url' => 'nullable|url|max:500',
        ]);

        $account = $request->attributes->get('supplier_account');
        try {
            $result = app(\App\Supply\SupplyOrderService::class)
                ->createOrder($account, $data, 'sync');

            return response()->json([
                'ok' => true,
                'supply_order_id' => $result['supply_order_id'],
                'order_id' => $result['order_id'],
                'amount' => $result['amount'],
                'fulfillment' => [
                    'type' => 'auto',
                    'status' => 'delivered',
                    'cards' => $result['cards'],
                ],
            ], 201);
        } catch (\App\Supply\Exceptions\SupplyApiException $e) {
            return response()->json([
                'ok' => false,
                'error_code' => $e->errorCode,
                'message' => $e->getMessage(),
            ], $e->httpStatus);
        }
    }
```

- [ ] **Step 2: 实现 show 端点**

替换 `show`：
```php
    public function show(Request $request, int $id): JsonResponse
    {
        $account = $request->attributes->get('supplier_account');
        $supplyOrder = \App\Models\SupplyOrder::where('supplier_account_id', $account->id)->find($id);
        if (! $supplyOrder) {
            return response()->json(['ok' => false, 'error_code' => 'order_not_found', 'message' => '订单不存在'], 404);
        }

        $order = $supplyOrder->order;
        $cards = \App\Models\Card::where('order_id', $order->id)->where('status', \App\Models\Card::STATUS_USED)->pluck('content')->all();

        return response()->json([
            'ok' => true,
            'supply_order_id' => $supplyOrder->id,
            'order_no' => $order->order_no,
            'status' => $order->status,
            'amount' => (int) $order->amount,
            'fulfillment' => ['type' => 'auto', 'status' => $order->delivery_status, 'cards' => $cards],
        ]);
    }
```

- [ ] **Step 3: 实现 cancel 端点（未发货才退）**

替换 `cancel`：
```php
    public function cancel(Request $request, int $id): JsonResponse
    {
        $account = $request->attributes->get('supplier_account');
        $supplyOrder = \App\Models\SupplyOrder::where('supplier_account_id', $account->id)->find($id);
        if (! $supplyOrder) {
            return response()->json(['ok' => false, 'error_code' => 'order_not_found'], 404);
        }

        // 已发货的供货订单不可取消(卡密已发)
        if ($supplyOrder->order->delivery_status === 'delivered') {
            return response()->json(['ok' => false, 'error_code' => 'order_not_cancelable', 'message' => __('messages.supply_api.order_not_cancelable')], 409);
        }

        return response()->json(['ok' => true, 'supply_order_id' => $supplyOrder->id, 'status' => 'canceled']);
    }
```

- [ ] **Step 4: 写订单 API 集成测试**

`tests/Feature/SupplyOrderApiTest.php`:
```php
<?php

namespace Tests\Feature;

use App\Models\Card;
use App\Models\Product;
use App\Models\SupplierAccount;
use App\Supply\HmacSigner;
use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;

class SupplyOrderApiTest extends TestCase
{
    use RefreshDatabase;

    private function signedHeaders(SupplierAccount $a, string $method, string $path, string $body = ''): array
    {
        $ts = (string) time();
        $nonce = 'n' . uniqid();
        $ss = HmacSigner::buildSignString($method, $path, $ts, $nonce, md5($body));
        return [
            'X-Supply-Key' => $a->api_key, 'X-Supply-Timestamp' => $ts,
            'X-Supply-Nonce' => $nonce, 'X-Supply-Signature' => HmacSigner::sign('sk', $ss),
        ];
    }

    public function test_create_order_returns_cards(): void
    {
        config(['zcard.features.supply' => true, 'zcard.supply.nonce_store' => 'cache']);
        $account = SupplierAccount::create(['name' => 'A', 'api_key' => 'ak', 'api_secret' => Crypt::encryptString('sk'), 'balance' => 100000, 'status' => 'active']);
        $product = Product::create(['merchant_id' => 1, 'name' => 'P', 'slug' => 'p1', 'price' => 500, 'factory_price' => 500, 'stock_type' => 'card', 'status' => 1]);
        Card::create(['product_id' => $product->id, 'content' => 'SECRET-1', 'status' => Card::STATUS_UNUSED]);

        $body = json_encode(['product_id' => $product->id, 'quantity' => 1, 'downstream_order_no' => 'D1']);
        $path = '/api/supply/orders';
        // 签名需用实际请求 body 的 md5
        $ts = (string) time(); $nonce = 'n' . uniqid();
        $ss = HmacSigner::buildSignString('POST', $path, $ts, $nonce, md5($body));
        $headers = [
            'X-Supply-Key' => 'ak', 'X-Supply-Timestamp' => $ts, 'X-Supply-Nonce' => $nonce,
            'X-Supply-Signature' => HmacSigner::sign('sk', $ss),
        ];

        $resp = $this->withHeaders($headers)->postJson($path, json_decode($body, true));

        $resp->assertStatus(201)->assertJsonPath('ok', true);
        $this->assertContains('SECRET-1', $resp->json('fulfillment.cards'));
        $this->assertSame(99500, (int) $account->fresh()->balance);
    }

    public function test_insufficient_balance_returns_402(): void
    {
        config(['zcard.features.supply' => true, 'zcard.supply.nonce_store' => 'cache']);
        $account = SupplierAccount::create(['name' => 'A', 'api_key' => 'ak', 'api_secret' => Crypt::encryptString('sk'), 'balance' => 100, 'status' => 'active']);
        $product = Product::create(['merchant_id' => 1, 'name' => 'P', 'slug' => 'p1', 'price' => 500, 'factory_price' => 500, 'stock_type' => 'card', 'status' => 1]);
        Card::create(['product_id' => $product->id, 'content' => 'C', 'status' => Card::STATUS_UNUSED]);

        $body = json_encode(['product_id' => $product->id, 'quantity' => 1, 'downstream_order_no' => 'D2']);
        $ts = (string) time(); $nonce = 'n' . uniqid();
        $ss = HmacSigner::buildSignString('POST', '/api/supply/orders', $ts, $nonce, md5($body));
        $headers = ['X-Supply-Key' => 'ak', 'X-Supply-Timestamp' => $ts, 'X-Supply-Nonce' => $nonce, 'X-Supply-Signature' => HmacSigner::sign('sk', $ss)];

        $resp = $this->withHeaders($headers)->postJson('/api/supply/orders', json_decode($body, true));
        $resp->assertStatus(402)->assertJsonPath('error_code', 'insufficient_balance');
    }
}
```

- [ ] **Step 5: 运行测试**

运行：
```bash
php artisan test --filter=SupplyOrderApiTest
```
预期：2 个测试通过。

- [ ] **Step 6: 提交**

```bash
git add app/Http/Controllers/Api/Supply/SupplyOrderController.php tests/Feature/SupplyOrderApiTest.php
git commit -m "feat(supply): 订单端点(create/show/cancel)+HTTP集成测试"
```

---

## Task 8.5: callback_url SSRF 校验（安全）

**Files:**
- Create: `app/Supply/CallbackUrlGuard.php`
- Create: `tests/Feature/CallbackUrlGuardTest.php`
- Modify: `app/Http/Controllers/Api/Supply/SupplyOrderController.php`

spec §8.5：`callback_url` 禁内网地址，仅 http/https。防止下游传内网地址触发服务端 SSRF。

- [ ] **Step 1: 写失败测试**

`tests/Feature/CallbackUrlGuardTest.php`:
```php
<?php

namespace Tests\Feature;

use App\Supply\CallbackUrlGuard;
use Tests\TestCase;

class CallbackUrlGuardTest extends TestCase
{
    /**
     * @dataProvider blockedUrls
     */
    public function test_blocked_urls_rejected(string $url): void
    {
        $this->assertFalse(app(CallbackUrlGuard::class)->isAllowed($url), "应拒绝: {$url}");
    }

    public static function blockedUrls(): array
    {
        return [
            '内网IP' => ['http://192.168.1.1/x'],
            '10段' => ['http://10.0.0.1/x'],
            '172段' => ['http://172.16.0.1/x'],
            'loopback' => ['http://127.0.0.1/x'],
            'link-local' => ['http://169.254.1.1/x'],
            'localhost' => ['http://localhost/x'],
            '非http' => ['ftp://example.com/x'],
        ];
    }

    /**
     * @dataProvider allowedUrls
     */
    public function test_allowed_urls_accepted(string $url): void
    {
        $this->assertTrue(app(CallbackUrlGuard::class)->isAllowed($url), "应允许: {$url}");
    }

    public static function allowedUrls(): array
    {
        return [
            'https公网' => ['https://example.com/callback'],
            'http公网' => ['http://203.0.113.5/callback'],
        ];
    }
}
```

- [ ] **Step 2: 运行测试确认失败**

运行：
```bash
php artisan test --filter=CallbackUrlGuardTest
```
预期：FAIL（类不存在）。

- [ ] **Step 3: 实现 CallbackUrlGuard**

`app/Supply/CallbackUrlGuard.php`:
```php
<?php

namespace App\Supply;

/**
 * callback_url SSRF 校验(spec §8.5)
 * 禁止内网/loopback/link-local 地址;仅允许 http/https。
 */
class CallbackUrlGuard
{
    /** 内网 IP 段(CIDR) */
    private const BLOCKED_RANGES = [
        '10.0.0.0/8',
        '172.16.0.0/12',
        '192.168.0.0/16',
        '127.0.0.0/8',
        '169.254.0.0/16', // link-local
        '0.0.0.0/8',
    ];

    public function isAllowed(string $url): bool
    {
        $parsed = parse_url($url);
        if ($parsed === false) return false;
        if (! in_array($parsed['scheme'] ?? '', ['http', 'https'])) return false;

        $host = $parsed['host'] ?? '';
        if ($host === '' || $host === 'localhost') return false;

        // 解析主机为 IP(若是域名)
        $ip = gethostbyname($host);
        if ($ip === $host && ! filter_var($host, FILTER_VALIDATE_IP)) {
            // 域名解析失败且非 IP 字面量 → 拒绝
            return false;
        }
        $ip = filter_var($ip, FILTER_VALIDATE_IP) ? $ip : $host;

        foreach (self::BLOCKED_RANGES as $range) {
            if ($this->ipInRange($ip, $range)) return false;
        }
        return true;
    }

    private function ipInRange(string $ip, string $range): bool
    {
        [$subnet, $maskBits] = explode('/', $range);
        $ipLong = ip2long($ip);
        $subnetLong = ip2long($subnet);
        if ($ipLong === false || $subnetLong === false) return false;
        $mask = -1 << (32 - (int) $maskBits);
        return ($ipLong & $mask) === ($subnetLong & $mask);
    }
}
```

- [ ] **Step 4: 在订单控制器校验 callback_url**

读取 `app/Http/Controllers/Api/Supply/SupplyOrderController.php` 的 `create` 方法，在 validate 之后、调 SupplyOrderService 之前加：
```php
        if (! empty($data['callback_url']) && ! app(\App\Supply\CallbackUrlGuard::class)->isAllowed($data['callback_url'])) {
            return response()->json(['ok' => false, 'error_code' => 'bad_request', 'message' => '回调地址不允许'], 400);
        }
```

- [ ] **Step 5: 运行测试确认通过**

运行：
```bash
php artisan test --filter=CallbackUrlGuardTest
```
预期：所有 blocked/allowed 用例通过。

- [ ] **Step 6: 提交**

```bash
git add app/Supply/CallbackUrlGuard.php tests/Feature/CallbackUrlGuardTest.php app/Http/Controllers/Api/Supply/SupplyOrderController.php
git commit -m "fix(supply): callback_url SSRF校验(禁内网/loopback/非http)"
```

---

## Task 9: OrderPaid 事件守卫（防止 supply 订单误触发分销/分站）

**Files:**
- Modify: `app/Support/CommissionService.php`
- Modify: `app/Support/SubsiteSettlementService.php`
- Create: `tests/Feature/SupplyOrderEventGuardTest.php`

spec §4.5.1：supply 订单不触发佣金/分站结算。

- [ ] **Step 1: 写失败测试**

`tests/Feature/SupplyOrderEventGuardTest.php`:
```php
<?php

namespace Tests\Feature;

use App\Events\OrderPaid;
use App\Models\Order;
use App\Models\SupplierLedgerEntry;
use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

class SupplyOrderEventGuardTest extends TestCase
{
    use RefreshDatabase;

    public function test_supply_order_does_not_create_commission(): void
    {
        config(['zcard.features.distribution' => true, 'zcard.features.supply' => true]);
        $order = Order::create([
            'order_no' => 'S1', 'merchant_id' => 1, 'product_id' => 1, 'quantity' => 1,
            'amount' => 1000, 'status' => 'paid', 'source' => 'supply', 'paid_at' => now(),
        ]);

        event(new OrderPaid($order));

        // 不应产生佣金记录(commissions 表为空)
        $this->assertDatabaseMissing('commissions', ['order_id' => $order->id]);
    }

    public function test_supply_order_does_not_create_subsite_settlement(): void
    {
        config(['zcard.features.sub_site' => true, 'zcard.features.supply' => true]);
        $order = Order::create([
            'order_no' => 'S2', 'merchant_id' => 1, 'product_id' => 1, 'quantity' => 1,
            'amount' => 1000, 'status' => 'paid', 'source' => 'supply', 'paid_at' => now(),
        ]);

        event(new OrderPaid($order));

        $this->assertDatabaseMissing('subsite_ledger_entries', ['order_id' => $order->id]);
    }
}
```

- [ ] **Step 2: 运行测试确认失败**

运行：
```bash
php artisan test --filter=SupplyOrderEventGuardTest
```
预期：可能通过也可能失败（取决于现有 listener 是否在无分销关系时也早退）。若已通过说明现有逻辑天然安全；若失败则需加守卫。

- [ ] **Step 3: 加守卫（若 Step 2 失败）**

读取 `app/Support/CommissionService.php`，在 `handle` 方法最开头（feature flag 检查之后）加：
```php
        if ($event->order->source === 'supply') {
            return; // 供货订单不参与分销(spec §4.5.1)
        }
```

读取 `app/Support/SubsiteSettlementService.php`，在 `handle` 方法最开头加同样守卫：
```php
        if ($event->order->source === 'supply') {
            return; // 供货订单不参与分站结算(spec §4.5.1)
        }
```

- [ ] **Step 4: 运行测试确认通过 + 全量回归**

运行：
```bash
php artisan test
```
预期：全部通过（含原有分销/分站测试 + 新守卫测试）。

- [ ] **Step 5: 提交**

```bash
git add app/Support/CommissionService.php app/Support/SubsiteSettlementService.php tests/Feature/SupplyOrderEventGuardTest.php
git commit -m "fix(supply): OrderPaid事件守卫,supply订单不触发分销/分站结算"
```

---

## Task 10: 供货账号管理后台 API（创建/重置/充值/账本/专属定价）

**Files:**
- Create: `app/Http/Controllers/Api/Admin/SupplierAccountController.php`
- Modify: `routes/api.php`

spec §7。复用 admin.role 中间件。

- [ ] **Step 1: 控制器（CRUD + 重置 + 充值 + 账本）**

`app/Http/Controllers/Api/Admin/SupplierAccountController.php`:
```php
<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\SupplierAccount;
use App\Models\SupplierLedgerEntry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * 供货账号管理(spec §7.1) —— admin.role 保护
 */
class SupplierAccountController extends Controller
{
    /** GET /api/admin/supplier-accounts */
    public function index(Request $request): JsonResponse
    {
        $accounts = SupplierAccount::query()
            ->when($request->input('status'), fn ($q, $s) => $q->where('status', $s))
            ->orderByDesc('id')
            ->paginate($request->integer('per_page', 20));

        // api_secret 脱敏(已在模型 $hidden)
        return response()->json($accounts);
    }

    /** POST /api/admin/supplier-accounts (生成 key/secret,明文仅此一次返回) */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => 'required|string|max:100',
            'contact' => 'nullable|string|max:200',
            'remark' => 'nullable|string|max:500',
        ]);

        $plainSecret = Str::random(64);
        $account = SupplierAccount::create([
            'name' => $data['name'],
            'api_key' => Str::random(32),
            'api_secret' => Crypt::encryptString($plainSecret),
            'balance' => 0,
            'status' => SupplierAccount::STATUS_ACTIVE,
            'contact' => $data['contact'] ?? null,
            'remark' => $data['remark'] ?? null,
        ]);

        // 明文 secret 仅此一次返回
        return response()->json([
            'id' => $account->id,
            'name' => $account->name,
            'api_key' => $account->api_key,
            'api_secret' => $plainSecret,
            'balance' => 0,
            'warning' => __('messages.supply.secret_show_once_warning'),
        ], 201);
    }

    /** GET /api/admin/supplier-accounts/{id} (凭证脱敏) */
    public function show(SupplierAccount $supplierAccount): JsonResponse
    {
        $supplierAccount->api_secret = $this->maskSecret($supplierAccount);
        return response()->json($supplierAccount->makeVisible(['api_secret']));
    }

    /** PUT /api/admin/supplier-accounts/{id} */
    public function update(Request $request, SupplierAccount $supplierAccount): JsonResponse
    {
        $data = $request->validate([
            'name' => 'sometimes|string|max:100',
            'status' => 'sometimes|in:active,disabled',
            'contact' => 'sometimes|nullable|string|max:200',
            'remark' => 'sometimes|nullable|string|max:500',
        ]);
        $supplierAccount->update($data);
        return response()->json($supplierAccount);
    }

    /** DELETE /api/admin/supplier-accounts/{id} */
    public function destroy(SupplierAccount $supplierAccount): JsonResponse
    {
        $supplierAccount->delete();
        return response()->json(null, 204);
    }

    /** POST /api/admin/supplier-accounts/{id}/reset-secret */
    public function resetSecret(SupplierAccount $supplierAccount): JsonResponse
    {
        $plainSecret = Str::random(64);
        $supplierAccount->update(['api_secret' => Crypt::encryptString($plainSecret)]);

        return response()->json([
            'id' => $supplierAccount->id,
            'api_key' => $supplierAccount->api_key,
            'api_secret' => $plainSecret,
            'warning' => __('messages.supply.secret_show_once_warning'),
        ]);
    }

    /** POST /api/admin/supplier-accounts/{id}/recharge */
    public function recharge(Request $request, SupplierAccount $supplierAccount): JsonResponse
    {
        $data = $request->validate([
            'amount' => 'required|integer|min:1', // 分
            'remark' => 'nullable|string|max:200',
        ]);

        $key = 'recharge_' . $supplierAccount->id . '_' . time() . '_' . random_int(1000, 9999);
        DB::transaction(function () use ($supplierAccount, $data, $key) {
            $locked = SupplierAccount::where('id', $supplierAccount->id)->lockForUpdate()->firstOrFail();
            $locked->increment('balance', $data['amount']);
            SupplierLedgerEntry::create([
                'supplier_account_id' => $locked->id,
                'type' => SupplierLedgerEntry::TYPE_RECHARGE,
                'amount' => $data['amount'],
                'balance_after' => $locked->fresh()->balance,
                'idempotency_key' => $key,
                'remark' => $data['remark'] ?? '管理员充值',
            ]);
        });

        return response()->json(['balance' => (int) $supplierAccount->fresh()->balance]);
    }

    /** GET /api/admin/supplier-accounts/{id}/ledger */
    public function ledger(Request $request, SupplierAccount $supplierAccount): JsonResponse
    {
        $entries = $supplierAccount->ledgerEntries()->orderByDesc('id')->paginate($request->integer('per_page', 20));
        return response()->json($entries);
    }

    private function maskSecret(SupplierAccount $account): string
    {
        try {
            $plain = Crypt::decryptString($account->getRawOriginal('api_secret'));
        } catch (\Throwable) {
            $plain = $account->getRawOriginal('api_secret');
        }
        return '••••••••' . substr($plain, -4);
    }
}
```

- [ ] **Step 2: 专属定价控制器（两个入口）**

`app/Http/Controllers/Api/Admin/SupplierPriceController.php`:
```php
<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\SupplierAccount;
use App\Models\SupplierProductPrice;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * 供货专属定价(spec §7.4) —— 账号维度 + 商品维度两个入口
 */
class SupplierPriceController extends Controller
{
    /** GET /api/admin/supplier-accounts/{account}/prices */
    public function indexForAccount(SupplierAccount $account, Request $request): JsonResponse
    {
        $prices = $account->productPrices()
            ->when($request->input('product_id'), fn ($q, $pid) => $q->where('product_id', $pid))
            ->with(['product:id,name,slug', 'sku:id,name'])
            ->orderByDesc('id')->paginate($request->integer('per_page', 50));
        return response()->json($prices);
    }

    /** PUT /api/admin/supplier-accounts/{account}/prices (批量) */
    public function updateForAccount(SupplierAccount $account, Request $request): JsonResponse
    {
        $data = $request->validate([
            'prices' => 'required|array',
            'prices.*.product_id' => 'required|exists:products,id',
            'prices.*.sku_id' => 'nullable|exists:product_skus,id',
            'prices.*.price' => 'required|integer|min:0',
        ]);

        foreach ($data['prices'] as $item) {
            SupplierProductPrice::updateOrCreate(
                [
                    'supplier_account_id' => $account->id,
                    'product_id' => $item['product_id'],
                    'sku_id' => $item['sku_id'] ?? null,
                ],
                ['price' => $item['price']]
            );
        }
        return response()->json(['ok' => true, 'count' => count($data['prices'])]);
    }

    /** DELETE /api/admin/supplier-accounts/{account}/prices/{price} */
    public function destroyForAccount(SupplierAccount $account, int $priceId): JsonResponse
    {
        SupplierProductPrice::where('supplier_account_id', $account->id)->where('id', $priceId)->delete();
        return response()->json(null, 204);
    }

    /** GET /api/admin/products/{product}/supply-prices (商品维度) */
    public function indexForProduct(Product $product): JsonResponse
    {
        $prices = SupplierProductPrice::where('product_id', $product->id)
            ->with(['supplierAccount:id,name', 'sku:id,name'])
            ->orderByDesc('id')->get();
        return response()->json(['prices' => $prices]);
    }

    /** PUT /api/admin/products/{product}/supply-prices (商品维度批量) */
    public function updateForProduct(Product $product, Request $request): JsonResponse
    {
        $data = $request->validate([
            'prices' => 'required|array',
            'prices.*.supplier_account_id' => 'required|exists:supplier_accounts,id',
            'prices.*.sku_id' => 'nullable|exists:product_skus,id',
            'prices.*.price' => 'required|integer|min:0',
        ]);

        foreach ($data['prices'] as $item) {
            SupplierProductPrice::updateOrCreate(
                [
                    'supplier_account_id' => $item['supplier_account_id'],
                    'product_id' => $product->id,
                    'sku_id' => $item['sku_id'] ?? null,
                ],
                ['price' => $item['price']]
            );
        }
        return response()->json(['ok' => true, 'count' => count($data['prices'])]);
    }
}
```

- [ ] **Step 3: 注册路由**

在 `routes/api.php` 的 admin 路由组内（`Route::middleware(['auth:sanctum', 'admin.role'])->prefix('admin')->group(...)`）追加：
```php
        // 供货账号管理(spec §7.1)
        Route::apiResource('supplier-accounts', \App\Http\Controllers\Api\Admin\SupplierAccountController::class)
            ->parameter('supplier-accounts', 'supplierAccount');
        Route::post('supplier-accounts/{supplierAccount}/reset-secret', [\App\Http\Controllers\Api\Admin\SupplierAccountController::class, 'resetSecret']);
        Route::post('supplier-accounts/{supplierAccount}/recharge', [\App\Http\Controllers\Api\Admin\SupplierAccountController::class, 'recharge']);
        Route::get('supplier-accounts/{supplierAccount}/ledger', [\App\Http\Controllers\Api\Admin\SupplierAccountController::class, 'ledger']);
        // 专属定价(账号维度)
        Route::get('supplier-accounts/{supplierAccount}/prices', [\App\Http\Controllers\Api\Admin\SupplierPriceController::class, 'indexForAccount']);
        Route::put('supplier-accounts/{supplierAccount}/prices', [\App\Http\Controllers\Api\Admin\SupplierPriceController::class, 'updateForAccount']);
        Route::delete('supplier-accounts/{supplierAccount}/prices/{priceId}', [\App\Http\Controllers\Api\Admin\SupplierPriceController::class, 'destroyForAccount']);
        // 专属定价(商品维度)
        Route::get('products/{product}/supply-prices', [\App\Http\Controllers\Api\Admin\SupplierPriceController::class, 'indexForProduct']);
        Route::put('products/{product}/supply-prices', [\App\Http\Controllers\Api\Admin\SupplierPriceController::class, 'updateForProduct']);
```

- [ ] **Step 4: 写账号管理测试**

`tests/Feature/SupplierAccountAdminTest.php`:
```php
<?php

namespace Tests\Feature;

use App\Models\SupplierAccount;
use App\Models\User;
use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

class SupplierAccountAdminTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $user = User::create(['username' => 'admin', 'name' => 'A', 'email' => 'a@x.com', 'password' => 'x', 'status' => 1]);
        $user->assignRole('super_admin');
        return $user;
    }

    public function test_create_account_returns_plaintext_secret_once(): void
    {
        config(['zcard.features.supply' => true]);
        $token = $this->admin()->createToken('test')->plainTextToken;

        $resp = $this->withToken($token)->postJson('/api/admin/supplier-accounts', ['name' => '下游A']);
        $resp->assertStatus(201)->assertJsonPath('name', '下游A');
        $this->assertNotEmpty($resp->json('api_secret'));
        $this->assertNotEmpty($resp->json('api_key'));

        // 详情里 secret 应脱敏
        $show = $this->withToken($token)->getJson('/api/admin/supplier-accounts/' . $resp->json('id'));
        $show->assertOk();
        $this->assertStringStartsWith('••••', $show->json('api_secret'));
    }

    public function test_recharge_increases_balance_and_writes_ledger(): void
    {
        config(['zcard.features.supply' => true]);
        $token = $this->admin()->createToken('test')->plainTextToken;
        $account = SupplierAccount::create(['name' => 'A', 'api_key' => 'k', 'api_secret' => 'enc', 'balance' => 0]);

        $resp = $this->withToken($token)->postJson("/api/admin/supplier-accounts/{$account->id}/recharge", ['amount' => 50000, 'remark' => '首充']);
        $resp->assertOk()->assertJsonPath('balance', 50000);
        $this->assertSame(50000, (int) $account->fresh()->balance);
        $this->assertDatabaseHas('supplier_ledger_entries', ['supplier_account_id' => $account->id, 'amount' => 50000]);
    }
}
```

- [ ] **Step 5: 运行测试**

运行：
```bash
php artisan test --filter=SupplierAccountAdminTest
```
预期：2 个测试通过。

- [ ] **Step 6: 提交**

```bash
git add app/Http/Controllers/Api/Admin/SupplierAccountController.php app/Http/Controllers/Api/Admin/SupplierPriceController.php routes/api.php tests/Feature/SupplierAccountAdminTest.php
git commit -m "feat(supply): 供货账号管理后台API(CRUD/重置secret/充值/账本/专属定价双入口)"
```

---

## Task 11: 全量回归 + Phase 2 完成验证

- [ ] **Step 1: 运行全量测试**

运行：
```bash
php artisan config:clear && php artisan test
```
预期：全部通过（原有 + Phase 1 + Phase 2 新增）。

- [ ] **Step 2: 清理缓存**

运行：
```bash
php artisan config:clear && php artisan route:clear
```

- [ ] **Step 3: 提交（若有遗漏修复）**

若 Step 1 发现回归问题，修复后：
```bash
git add -A
git commit -m "fix(supply): Phase 2 回归修复"
```

---

## Phase 2 完成标准

- [ ] 多语言文案（zh_CN/en）就绪
- [ ] NonceStore 三后端（redis/cache/database）防重放
- [ ] HMAC 四头鉴权中间件（key/timestamp/nonce/signature），5 个测试通过
- [ ] 供货 API 路由组 `/api/supply/*` + ping 端点
- [ ] SupplyPricingService 专属价查找（SKU级→商品级→factory_price），3 测试通过
- [ ] 商品端点（index/show/stock/categories）+ 专属价注入
- [ ] SupplyOrderService 供货下单（扣预存+发卡+幂等+校验），4 测试通过
- [ ] 订单端点（create/show/cancel）+ HTTP 集成测试
- [ ] OrderPaid 事件守卫（supply 订单不触发分销/分站）
- [ ] 供货账号管理后台 API（CRUD/重置/充值/账本/专属定价双入口）
- [ ] 全量 `php artisan test` 无回归

**Phase 3 将实现：** 后台货源对接设置（驱动自描述表单 + 测试连通 + 同步触发）+ 商品同步（SyncService + Job + 售价保护）+ 下游拿货编排（同步/异步发卡回退 + 回调接收）+ 三驱动 HTTP 调用实现 + Filament/sysadmin 界面。
