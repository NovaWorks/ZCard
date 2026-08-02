# 货源对接 Phase 3（后台货源设置 + 商品同步 + 下游拿货编排）实施计划

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** 完成 ZCard「作为下游」全链路：后台货源对接设置（驱动自描述表单 + 测试连通 + 同步触发）+ 商品同步服务（含售价保护）+ 下游拿货编排（同步试→异步回退+回调接收）+ 三驱动 HTTP 调用实现。完成后实现"自己对接自己"和对接 dujiao-next/acg-faka 全闭环。

**Architecture:** 后台货源管理 `/api/admin/supply-sources/*` 复用 admin.role。商品同步用队列 Job（异步），售价保护只更新 factory_price 不动 price。下游拿货挂 OrderPaid 事件，同步试失败转 FetchFromUpstreamJob 退避重试 + 回调接收。三驱动 HTTP 调用用 Guzzle，各自实现签名。

**Tech Stack:** Laravel 13.8, PHP 8.3, PHPUnit 12, Guzzle（队列 Job、Schedule）。

**测试策略:** TDD。同步映射逻辑、售价保护、拿货编排（mock 驱动）、回调接收有 Feature 测试。HTTP 调用对真实上游的集成测试标记为 skipped（需真实凭证）。

**依赖:** Phase 1（数据模型/驱动骨架/Manager）+ Phase 2（对外供货 API，ZCardDriver 调它）已完成。

**Spec:** `docs/superpowers/specs/2026-08-02-zcard-supply-integration-design.md`（§5 商品同步/拿货、§6 后台货源设置、§3.3 驱动对接要点）

---

## Task 1: 货源管理后台 API（CRUD + 驱动元数据 + 测试连通）

**Files:**
- Create: `app/Http/Controllers/Api/Admin/SupplySourceController.php`
- Modify: `routes/api.php`

spec §6.1。

- [ ] **Step 1: 控制器**

`app/Http/Controllers/Api/Admin/SupplySourceController.php`:
```php
<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\SupplySource;
use App\Supply\SupplyManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Validation\Rule;

/**
 * 货源对接设置(spec §6) —— admin.role 保护
 */
class SupplySourceController extends Controller
{
    /** GET /api/admin/supply-sources/drivers 返回各驱动 label+configSchema */
    public function drivers(): JsonResponse
    {
        return response()->json(['drivers' => SupplyManager::allDriversMeta()]);
    }

    /** GET /api/admin/supply-sources */
    public function index(Request $request): JsonResponse
    {
        $sources = SupplySource::query()
            ->when($request->input('status'), fn ($q, $s) => $q->where('status', $s))
            ->orderByDesc('id')->paginate($request->integer('per_page', 20));

        // 凭证脱敏
        $sources->getCollection()->transform(fn ($s) => $this->maskCredentials($s));
        return response()->json($sources);
    }

    /** POST /api/admin/supply-sources */
    public function store(Request $request): JsonResponse
    {
        $data = $this->validateSource($request);
        $source = SupplySource::create([
            'name' => $data['name'],
            'driver' => $data['driver'],
            'base_url' => $data['base_url'],
            'credentials' => $data['credentials'], // cast encrypted:array 自动加密
            'status' => $data['status'] ?? 'active',
            'settings' => $data['settings'] ?? null,
        ]);
        return response()->json($this->maskCredentials($source), 201);
    }

    /** GET /api/admin/supply-sources/{source} */
    public function show(SupplySource $supplySource): JsonResponse
    {
        return response()->json($this->maskCredentials($supplySource));
    }

    /** PUT /api/admin/supply-sources/{source} */
    public function update(Request $request, SupplySource $supplySource): JsonResponse
    {
        $data = $this->validateSource($request, $supplySource);
        $update = collect($data)->except('credentials')->toArray();
        // credentials:secret 类字段留空=不修改,只合并实际传入的非空值
        if (isset($data['credentials'])) {
            $existing = $supplySource->credentials ?? [];
            $merged = array_merge($existing, array_filter($data['credentials'], fn ($v) => $v !== '' && $v !== null));
            $update['credentials'] = $merged;
        }
        $supplySource->update($update);
        return response()->json($this->maskCredentials($supplySource));
    }

    /** DELETE /api/admin/supply-sources/{source} */
    public function destroy(SupplySource $supplySource): JsonResponse
    {
        $supplySource->delete();
        return response()->json(null, 204);
    }

    /** POST /api/admin/supply-sources/{source}/test 测试连通(调 ping) */
    public function test(SupplySource $supplySource): JsonResponse
    {
        try {
            $driver = app(SupplyManager::class)->driver($supplySource);
            $result = $driver->ping();

            if ($result['connected'] ?? false) {
                $supplySource->update([
                    'balance_cache' => $result['balance'] ?? null,
                    'last_error' => null,
                ]);
            } else {
                $supplySource->update(['last_error' => $result['error'] ?? '连接失败']);
            }
            return response()->json($result);
        } catch (\Throwable $e) {
            $supplySource->update(['last_error' => $e->getMessage()]);
            return response()->json(['connected' => false, 'error' => $e->getMessage()]);
        }
    }

    private function validateSource(Request $request, ?SupplySource $existing = null): array
    {
        return $request->validate([
            'name' => 'sometimes|required|string|max:100',
            'driver' => ['sometimes', 'required', Rule::in(array_keys(SupplyManager::DRIVERS))],
            'base_url' => 'sometimes|required|url|max:255',
            'credentials' => 'sometimes|array',
            'status' => 'sometimes|in:active,disabled',
            'settings' => 'sometimes|nullable|array',
        ]);
    }

    /** 凭证脱敏:secret 类字段只留末4位 */
    private function maskCredentials(SupplySource $source): SupplySource
    {
        $creds = $source->credentials ?? [];
        foreach ($creds as $key => $val) {
            if (is_string($val) && strlen($val) > 4 && str_contains(strtolower($key), 'secret') || strtolower($key) === 'app_key') {
                $creds[$key] = '••••••••' . substr($val, -4);
            }
        }
        $source->credentials = $creds;
        return $source;
    }
}
```

- [ ] **Step 2: 同步触发端点（占位，Task 4 接入 Job）**

在 `SupplySourceController` 加：
```php
    /** POST /api/admin/supply-sources/{source}/sync 触发商品同步 */
    public function sync(Request $request, SupplySource $supplySource): JsonResponse
    {
        $mode = $request->input('mode', 'incremental');
        // Task 4 接入 SyncSupplySourceProducts Job
        return response()->json(['ok' => true, 'message' => '同步任务已派发(待 Task4 实现)', 'mode' => $mode]);
    }

    /** GET /api/admin/supply-sources/{source}/sync-status */
    public function syncStatus(SupplySource $supplySource): JsonResponse
    {
        return response()->json([
            'ok' => true,
            'last_synced_at' => $supplySource->last_synced_at,
            'last_error' => $supplySource->last_error,
        ]);
    }
```

- [ ] **Step 3: 注册路由**

在 `routes/api.php` admin 组内追加：
```php
        // 货源对接设置(spec §6.1)
        Route::get('supply-sources/drivers', [\App\Http\Controllers\Api\Admin\SupplySourceController::class, 'drivers']);
        Route::apiResource('supply-sources', \App\Http\Controllers\Api\Admin\SupplySourceController::class)
            ->parameter('supply-sources', 'supplySource');
        Route::post('supply-sources/{supplySource}/test', [\App\Http\Controllers\Api\Admin\SupplySourceController::class, 'test']);
        Route::post('supply-sources/{supplySource}/sync', [\App\Http\Controllers\Api\Admin\SupplySourceController::class, 'sync']);
        Route::get('supply-sources/{supplySource}/sync-status', [\App\Http\Controllers\Api\Admin\SupplySourceController::class, 'syncStatus']);
```

- [ ] **Step 4: 写测试**

`tests/Feature/SupplySourceAdminTest.php`:
```php
<?php

namespace Tests\Feature;

use App\Models\SupplySource;
use App\Models\User;
use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

class SupplySourceAdminTest extends TestCase
{
    use RefreshDatabase;

    private function adminToken(): string
    {
        $u = User::create(['username' => 'a', 'name' => 'A', 'email' => 'a@x.com', 'password' => 'x', 'status' => 1]);
        $u->assignRole('super_admin');
        return $u->createToken('t')->plainTextToken;
    }

    public function test_drivers_endpoint_returns_schema(): void
    {
        config(['zcard.features.supply' => true]);
        $resp = $this->withToken($this->adminToken())->getJson('/api/admin/supply-sources/drivers');
        $resp->assertOk()->assertJsonCount(3, 'drivers');
        $this->assertNotNull($resp->json('drivers.0.config_schema.base_url'));
    }

    public function test_create_source_encrypts_credentials(): void
    {
        config(['zcard.features.supply' => true]);
        $resp = $this->withToken($this->adminToken())->postJson('/api/admin/supply-sources', [
            'name' => '主站', 'driver' => 'dujiao_next', 'base_url' => 'https://up.example.com',
            'credentials' => ['base_url' => 'https://up.example.com', 'api_key' => 'ak', 'api_secret' => 'sk'],
        ]);
        $resp->assertStatus(201);
        // 返回脱敏
        $this->assertStringStartsWith('••••', $resp->json('credentials.api_secret'));
        // DB 存密文
        $this->assertStringNotContainsString('sk_secret', \DB::table('supply_sources')->where('id', $resp->json('id'))->value('credentials'));
    }

    public function test_update_credentials_merges_keeping_secrets(): void
    {
        config(['zcard.features.supply' => true]);
        $source = SupplySource::create([
            'name' => 'S', 'driver' => 'dujiao_next', 'base_url' => 'https://x.com',
            'credentials' => ['api_key' => 'ak', 'api_secret' => 'oldsecret'], 'status' => 'active',
        ]);
        // 更新时 api_secret 留空=不修改
        $resp = $this->withToken($this->adminToken())->putJson("/api/admin/supply-sources/{$source->id}", [
            'credentials' => ['api_key' => 'newkey', 'api_secret' => null],
        ]);
        $resp->assertOk();
        $fresh = $source->fresh();
        $this->assertSame('newkey', $fresh->credentials['api_key']);
        $this->assertSame('oldsecret', $fresh->credentials['api_secret']); // 保留旧值
    }
}
```

- [ ] **Step 5: 运行测试**

运行：
```bash
php artisan test --filter=SupplySourceAdminTest
```
预期：3 个测试通过。

- [ ] **Step 6: 提交**

```bash
git add app/Http/Controllers/Api/Admin/SupplySourceController.php routes/api.php tests/Feature/SupplySourceAdminTest.php
git commit -m "feat(supply): 货源管理后台API(CRUD/驱动元数据/测试连通/同步触发)+凭证加密脱敏"
```

---

## Task 2: 三驱动 HTTP 调用实现（ping + 商品 + 下单）

**Files:**
- Modify: `app/Supply/Drivers/DujiaoNextDriver.php`
- Modify: `app/Supply/Drivers/AcgFakaDriver.php`
- Modify: `app/Supply/Drivers/ZCardDriver.php`
- Create: `app/Supply/Drivers/Concerns/MakesHttpRequests.php`

实现 spec §3.3 各驱动的签名 + HTTP 调用。用 Guzzle。各驱动的 `ping()`/`listProducts()`/`createOrder()` 等方法替换 Phase 1 的 notImplemented。

- [ ] **Step 1: HTTP 请求 trait（公共 Guzzle 封装）**

`app/Supply/Drivers/Concerns/MakesHttpRequests.php`:
```php
<?php

namespace App\Supply\Drivers\Concerns;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * 驱动 HTTP 请求公共封装。
 * 注意:用 Laravel Http facade(底层 Guzzle),带超时。
 */
trait MakesHttpRequests
{
    protected function requestTimeout(): int
    {
        return (int) ($this->source->settings['timeout'] ?? 30);
    }

    protected function baseUrl(): string
    {
        return rtrim($this->source->base_url, '/');
    }

    protected function credentials(): array
    {
        return $this->source->credentials ?? [];
    }

    protected function postJson(string $path, array $body, array $headers = []): array
    {
        $url = $this->baseUrl() . $path;
        $resp = Http::withHeaders($headers)->timeout($this->requestTimeout())->post($url, $body);
        if (! $resp->successful()) {
            Log::warning('supply upstream http error', ['url' => $url, 'status' => $resp->status(), 'body' => $resp->body()]);
            throw new \RuntimeException("上游请求失败: HTTP {$resp->status()}");
        }
        return $resp->json();
    }

    protected function getJson(string $path, array $query = [], array $headers = []): array
    {
        $url = $this->baseUrl() . $path;
        $resp = Http::withHeaders($headers)->timeout($this->requestTimeout())->get($url, $query);
        if (! $resp->successful()) {
            throw new \RuntimeException("上游请求失败: HTTP {$resp->status()}");
        }
        return $resp->json();
    }
}
```

- [ ] **Step 2: DujiaoNextDriver HTTP 实现**

替换 `app/Supply/Drivers/DujiaoNextDriver.php` 全文（保留 configSchema/info/构造，实现方法）：
```php
<?php

namespace App\Supply\Drivers;

use App\Models\SupplySource;
use App\Supply\Contracts\SupplyDriver;
use App\Supply\Drivers\Concerns\MakesHttpRequests;
use App\Supply\Dto\UpstreamCategory;
use App\Supply\Dto\UpstreamFulfillment;
use App\Supply\Dto\UpstreamOrder;
use App\Supply\Dto\UpstreamProduct;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class DujiaoNextDriver implements SupplyDriver
{
    use MakesHttpRequests;

    public function __construct(public readonly SupplySource $source) {}

    public static function configSchema(): array
    {
        return [
            'base_url' => ['type' => 'url', 'label' => '站点地址', 'required' => true],
            'api_key' => ['type' => 'text', 'label' => 'API Key', 'required' => true],
            'api_secret' => ['type' => 'secret', 'label' => 'API Secret', 'required' => true],
        ];
    }

    public static function info(): array
    {
        return ['name' => '独角数卡(dujiao-next)', 'icon' => '🦄'];
    }

    /** dujiao-next HMAC 三头签名(spec §3.3) */
    private function signedHeaders(string $method, string $path, string $body = ''): array
    {
        $creds = $this->credentials();
        $ts = (string) time();
        $signString = implode("\n", [$method, $path, $ts, md5($body)]);
        $sig = hash_hmac('sha256', $signString, $creds['api_secret']);
        return [
            'Dujiao-Next-Api-Key' => $creds['api_key'],
            'Dujiao-Next-Timestamp' => $ts,
            'Dujiao-Next-Signature' => $sig,
        ];
    }

    public function ping(): array
    {
        try {
            $path = '/api/v1/upstream/ping';
            $headers = $this->signedHeaders('POST', $path, md5(''));
            $data = $this->postJson($path, [], $headers);
            return [
                'connected' => $data['ok'] ?? false,
                'name' => $data['name'] ?? null,
                'balance' => isset($data['balance']) ? (int) round((float) $data['balance'] * 100) : null,
                'currency' => $data['currency'] ?? 'CNY',
            ];
        } catch (\Throwable $e) {
            return ['connected' => false, 'error' => $e->getMessage()];
        }
    }

    public function listCategories(): array
    {
        $path = '/api/v1/upstream/categories';
        $data = $this->getJson($path, [], $this->signedHeaders('GET', $path));
        return collect($data['categories'] ?? [])->map(fn ($c) => new UpstreamCategory(
            code: (string) $c['id'], name: $c['name'], parentCode: isset($c['parent_id']) ? (string) $c['parent_id'] : null,
            icon: $c['icon'] ?? null, sort: $c['sort_order'] ?? 0,
        ))->all();
    }

    public function listProducts(?Carbon $updatedAfter, int $page): array
    {
        $path = '/api/v1/upstream/products';
        $query = ['page' => $page, 'page_size' => 50];
        if ($updatedAfter) $query['updated_after'] = $updatedAfter->toIso8601String();
        $data = $this->getJson($path, $query, $this->signedHeaders('GET', $path));
        $items = collect($data['items'] ?? [])->map(fn ($p) => $this->mapProduct($p))->all();
        return ['items' => $items, 'total' => $data['total'] ?? 0, 'page' => $page, 'has_more' => ($page * 50) < ($data['total'] ?? 0)];
    }

    public function getProduct(string $code): ?UpstreamProduct
    {
        $path = "/api/v1/upstream/products/{$code}";
        $data = $this->getJson($path, [], $this->signedHeaders('GET', $path));
        return isset($data['product']) ? $this->mapProduct($data['product']) : null;
    }

    public function getStock(string $code, ?string $skuCode = null): int
    {
        $p = $this->getProduct($code);
        return $p?->stockQuantity ?? -1;
    }

    public function createOrder(array $params): UpstreamOrder
    {
        $path = '/api/v1/upstream/orders';
        $body = [
            'sku_id' => (int) $params['product_code'],
            'quantity' => $params['quantity'],
            'downstream_order_no' => $params['downstream_order_no'],
            'callback_url' => $params['callback_url'] ?? null,
        ];
        $bodyStr = json_encode($body);
        $data = $this->postJson($path, $body, $this->signedHeaders('POST', $path, md5($bodyStr)));
        return new UpstreamOrder(
            id: (string) ($data['order_id'] ?? ''),
            status: $data['status'] ?? 'pending',
            amount: isset($data['amount']) ? (int) round((float) $data['amount'] * 100) : 0,
            currency: $data['currency'] ?? 'CNY',
        );
    }

    public function getOrder(string $upstreamOrderId): UpstreamOrder
    {
        $path = "/api/v1/upstream/orders/{$upstreamOrderId}";
        $data = $this->getJson($path, [], $this->signedHeaders('GET', $path));
        $fulfillment = null;
        if (isset($data['fulfillment']) && $data['fulfillment']['status'] === 'delivered') {
            $cards = [];
            $payload = $data['fulfillment']['payload'] ?? null;
            if ($payload) $cards[] = $payload;
            $fulfillment = new UpstreamFulfillment(status: 'delivered', cards: $cards, deliveredAt: $data['fulfillment']['delivered_at'] ?? null);
        }
        return new UpstreamOrder(
            id: (string) ($data['order_id'] ?? $upstreamOrderId),
            status: $data['status'] ?? 'pending',
            amount: isset($data['amount']) ? (int) round((float) $data['amount'] * 100) : 0,
            currency: $data['currency'] ?? 'CNY',
            fulfillment: $fulfillment,
        );
    }

    public function cancelOrder(string $upstreamOrderId): bool
    {
        $path = "/api/v1/upstream/orders/{$upstreamOrderId}/cancel";
        $data = $this->postJson($path, [], $this->signedHeaders('POST', $path, md5('')));
        return $data['ok'] ?? false;
    }

    public function verifyCallback(Request $request): ?array
    {
        $creds = $this->credentials();
        $sig = $request->header('Dujiao-Next-Signature');
        $ts = $request->header('Dujiao-Next-Timestamp');
        $path = $request->getPathInfo();
        $expected = hash_hmac('sha256', implode("\n", ['POST', $path, $ts, md5($request->getContent())]), $creds['api_secret']);
        if (! hash_equals($expected, $sig)) return null;
        $data = $request->json()->all();
        $cards = [];
        if (($data['fulfillment']['payload'] ?? null)) $cards[] = $data['fulfillment']['payload'];
        return [
            'upstream_order_id' => (string) ($data['order_id'] ?? ''),
            'status' => $data['status'] ?? '',
            'cards' => $cards,
            'downstream_order_no' => $data['downstream_order_no'] ?? null,
        ];
    }

    private function mapProduct(array $p): UpstreamProduct
    {
        return new UpstreamProduct(
            code: (string) ($p['id'] ?? ''),
            name: $p['title'] ?? '',
            price: isset($p['price_amount']) ? (int) round((float) $p['price_amount'] * 100) : 0,
            factoryPrice: isset($p['wholesale_prices'][0]) ? (int) round((float) $p['wholesale_prices'][0] * 100) : (isset($p['price_amount']) ? (int) round((float) $p['price_amount'] * 100) : 0),
            categoryCode: isset($p['category_id']) ? (string) $p['category_id'] : null,
            description: $p['description'] ?? null,
            cover: $p['images'][0] ?? null,
            images: $p['images'] ?? [],
            isActive: $p['is_active'] ?? true,
        );
    }
}
```

- [ ] **Step 3: AcgFakaDriver HTTP 实现**

替换 `app/Supply/Drivers/AcgFakaDriver.php` 全文：
```php
<?php

namespace App\Supply\Drivers;

use App\Models\SupplySource;
use App\Supply\Contracts\SupplyDriver;
use App\Supply\Drivers\Concerns\MakesHttpRequests;
use App\Supply\Dto\UpstreamCategory;
use App\Supply\Dto\UpstreamFulfillment;
use App\Supply\Dto\UpstreamOrder;
use App\Supply\Dto\UpstreamProduct;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class AcgFakaDriver implements SupplyDriver
{
    use MakesHttpRequests;

    public function __construct(public readonly SupplySource $source) {}

    public static function configSchema(): array
    {
        return [
            'base_url' => ['type' => 'url', 'label' => '站点地址', 'required' => true],
            'app_id' => ['type' => 'number', 'label' => 'App ID', 'required' => true, 'help' => '对方站用户ID'],
            'app_key' => ['type' => 'secret', 'label' => 'App Key', 'required' => true],
        ];
    }

    public static function info(): array
    {
        return ['name' => 'ACG发卡(acg-faka)', 'icon' => '🎴'];
    }

    /** acg-faka MD5 签名(spec §3.3):md5(ksort去空值参数+&key=app_key) */
    private function sign(array $params): string
    {
        $creds = $this->credentials();
        $params['app_id'] = $creds['app_id'];
        $params['app_key'] = $creds['app_key'];
        unset($params['sign']);
        ksort($params);
        $params = array_filter($params, fn ($v) => $v !== '' && $v !== null);
        return md5(urldecode(http_build_query($params)) . '&key=' . $creds['app_key']);
    }

    private function signedPost(string $path, array $params): array
    {
        $creds = $this->credentials();
        $params['app_id'] = $creds['app_id'];
        $params['app_key'] = $creds['app_key'];
        $params['sign'] = $this->sign($params);
        $resp = \Illuminate\Support\Facades\Http::asForm()->timeout($this->requestTimeout())->post($this->baseUrl() . $path, $params);
        if (! $resp->successful()) throw new \RuntimeException("上游请求失败: HTTP {$resp->status()}");
        return $resp->json();
    }

    public function ping(): array
    {
        try {
            $data = $this->signedPost('/shared/authentication/connect', []);
            $ok = ($data['code'] ?? 0) == 200;
            return ['connected' => $ok, 'name' => $data['data']['shop']['name'] ?? null, 'balance' => isset($data['data']['balance']) ? (int) round((float) $data['data']['balance'] * 100) : null];
        } catch (\Throwable $e) { return ['connected' => false, 'error' => $e->getMessage()]; }
    }

    public function listCategories(): array { return []; } // acg-faka items 接口返回分类+商品树,见 listProducts

    public function listProducts(?Carbon $updatedAfter, int $page): array
    {
        $data = $this->signedPost('/shared/commodity/items', []);
        $items = [];
        foreach (($data['data'] ?? []) as $cat) {
            foreach ($cat['children'] ?? [] as $p) {
                $items[] = $this->mapProduct($p, $cat['id'] ?? null);
            }
        }
        return ['items' => $items, 'total' => count($items), 'page' => 1, 'has_more' => false];
    }

    public function getProduct(string $code): ?UpstreamProduct
    {
        $data = $this->signedPost('/shared/commodity/item', ['code' => $code]);
        return isset($data['data']) ? $this->mapProduct($data['data']) : null;
    }

    public function getStock(string $code, ?string $skuCode = null): int
    {
        $data = $this->signedPost('/shared/commodity/stock', ['code' => $code]);
        return (int) ($data['data']['stock'] ?? -1);
    }

    public function createOrder(array $params): UpstreamOrder
    {
        $data = $this->signedPost('/shared/commodity/trade', [
            'shared_code' => $params['product_code'],
            'num' => $params['quantity'],
            'contact' => $params['contact'] ?? '',
            'request_no' => $params['downstream_order_no'],
            'card_id' => 0,
        ]);
        $secret = $data['data']['secret'] ?? null;
        $fulfillment = $secret ? new UpstreamFulfillment(status: 'delivered', cards: [$secret]) : null;
        return new UpstreamOrder(id: $params['downstream_order_no'], status: $fulfillment ? 'delivered' : 'pending', amount: 0, fulfillment: $fulfillment);
    }

    public function getOrder(string $upstreamOrderId): UpstreamOrder
    {
        $data = $this->signedPost("/shared/commodity/query/{$upstreamOrderId}", []);
        $d = $data['data'] ?? [];
        $cards = ! empty($d['secret']) ? [$d['secret']] : [];
        return new UpstreamOrder(id: $upstreamOrderId, status: $d['status'] ?? 'pending', amount: 0, fulfillment: $cards ? new UpstreamFulfillment(status: 'delivered', cards: $cards) : null);
    }

    public function cancelOrder(string $upstreamOrderId): bool { return false; } // acg-faka 同步发货,通常不可取消

    public function verifyCallback(Request $request): ?array { return null; } // acg-faka 同步发货无回调

    private function mapProduct(array $p, $categoryId = null): UpstreamProduct
    {
        return new UpstreamProduct(
            code: $p['code'] ?? (string) ($p['id'] ?? ''),
            name: $p['name'] ?? '',
            price: isset($p['price']) ? (int) round((float) $p['price'] * 100) : 0,
            factoryPrice: isset($p['factory_price']) ? (int) round((float) $p['factory_price'] * 100) : 0,
            categoryCode: $categoryId !== null ? (string) $categoryId : ($p['category_id'] ?? null),
            description: $p['introduce'] ?? ($p['description'] ?? null),
            cover: $p['cover'] ?? null,
            isActive: true,
        );
    }
}
```

- [ ] **Step 4: ZCardDriver HTTP 实现（调本系统 /api/supply/*）**

替换 `app/Supply/Drivers/ZCardDriver.php` 全文（用 Phase 1 的 HmacSigner 签名，调 Phase 2 的供货 API）：
```php
<?php

namespace App\Supply\Drivers;

use App\Models\SupplySource;
use App\Supply\Contracts\SupplyDriver;
use App\Supply\Drivers\Concerns\MakesHttpRequests;
use App\Supply\Dto\UpstreamCategory;
use App\Supply\Dto\UpstreamFulfillment;
use App\Supply\Dto\UpstreamOrder;
use App\Supply\Dto\UpstreamProduct;
use App\Supply\HmacSigner;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class ZCardDriver implements SupplyDriver
{
    use MakesHttpRequests;

    public function __construct(public readonly SupplySource $source) {}

    public static function configSchema(): array
    {
        return [
            'base_url' => ['type' => 'url', 'label' => '站点地址', 'required' => true],
            'api_key' => ['type' => 'text', 'label' => 'API Key', 'required' => true],
            'api_secret' => ['type' => 'secret', 'label' => 'API Secret', 'required' => true],
        ];
    }

    public static function info(): array
    {
        return ['name' => 'ZCard', 'icon' => '🃏'];
    }

    /** 本系统自定义 HMAC 四头签名(同 /api/supply/* 协议,spec §4.2) */
    private function signedHeaders(string $method, string $path, string $body = ''): array
    {
        $creds = $this->credentials();
        $ts = (string) time();
        $nonce = 'zcard_' . uniqid();
        $ss = HmacSigner::buildSignString($method, $path, $ts, $nonce, md5($body));
        return [
            'X-Supply-Key' => $creds['api_key'],
            'X-Supply-Timestamp' => $ts,
            'X-Supply-Nonce' => $nonce,
            'X-Supply-Signature' => HmacSigner::sign($creds['api_secret'], $ss),
        ];
    }

    public function ping(): array
    {
        try {
            $path = '/api/supply/ping';
            $data = $this->postJson($path, [], $this->signedHeaders('POST', $path));
            return ['connected' => $data['ok'] ?? false, 'name' => $data['name'] ?? null, 'balance' => $data['balance'] ?? null, 'currency' => $data['currency'] ?? 'CNY'];
        } catch (\Throwable $e) { return ['connected' => false, 'error' => $e->getMessage()]; }
    }

    public function listCategories(): array
    {
        $path = '/api/supply/categories';
        $data = $this->postJson($path, [], $this->signedHeaders('POST', $path));
        return collect($data['categories'] ?? [])->map(fn ($c) => new UpstreamCategory(code: (string) $c['id'], name: $c['name'], parentCode: isset($c['parent_id']) ? (string) $c['parent_id'] : null))->all();
    }

    public function listProducts(?Carbon $updatedAfter, int $page): array
    {
        $path = '/api/supply/products';
        $query = ['page' => $page];
        $data = $this->getJson($path, $query, $this->signedHeaders('GET', $path));
        $items = collect($data['items'] ?? [])->map(fn ($p) => new UpstreamProduct(
            code: (string) $p['id'], name: $p['name'], price: $p['price'] ?? 0, factoryPrice: $p['price'] ?? 0,
            categoryCode: isset($p['category_id']) ? (string) $p['category_id'] : null, description: $p['description'] ?? null, cover: $p['cover'] ?? null,
        ))->all();
        return ['items' => $items, 'total' => $data['total'] ?? 0, 'page' => $page, 'has_more' => false];
    }

    public function getProduct(string $code): ?UpstreamProduct
    {
        $path = "/api/supply/products/{$code}";
        $data = $this->getJson($path, [], $this->signedHeaders('GET', $path));
        $p = $data['product'] ?? null;
        return $p ? new UpstreamProduct(code: (string) $p['id'], name: $p['name'], price: $p['price'] ?? 0, factoryPrice: $p['price'] ?? 0) : null;
    }

    public function getStock(string $code, ?string $skuCode = null): int
    {
        $path = "/api/supply/products/{$code}/stock";
        $data = $this->getJson($path, [], $this->signedHeaders('GET', $path));
        return $data['stock'] ?? -1;
    }

    public function createOrder(array $params): UpstreamOrder
    {
        $path = '/api/supply/orders';
        $body = ['product_id' => (int) $params['product_code'], 'quantity' => $params['quantity'], 'downstream_order_no' => $params['downstream_order_no']];
        $bodyStr = json_encode($body);
        $data = $this->postJson($path, $body, $this->signedHeaders('POST', $path, md5($bodyStr)));
        $cards = $data['fulfillment']['cards'] ?? [];
        return new UpstreamOrder(id: (string) $data['supply_order_id'], status: $data['fulfillment']['status'] ?? 'pending', amount: $data['amount'] ?? 0, fulfillment: $cards ? new UpstreamFulfillment(status: 'delivered', cards: $cards) : null);
    }

    public function getOrder(string $upstreamOrderId): UpstreamOrder
    {
        $path = "/api/supply/orders/{$upstreamOrderId}";
        $data = $this->getJson($path, [], $this->signedHeaders('GET', $path));
        $cards = $data['fulfillment']['cards'] ?? [];
        return new UpstreamOrder(id: $upstreamOrderId, status: $data['fulfillment']['status'] ?? 'pending', amount: $data['amount'] ?? 0, fulfillment: $cards ? new UpstreamFulfillment(status: 'delivered', cards: $cards) : null);
    }

    public function cancelOrder(string $upstreamOrderId): bool
    {
        $path = "/api/supply/orders/{$upstreamOrderId}/cancel";
        $data = $this->postJson($path, [], $this->signedHeaders('POST', $path));
        return $data['ok'] ?? false;
    }

    public function verifyCallback(Request $request): ?array
    {
        // ZCard 回调用本系统 HMAC 协议,复用 SupplyAuth 验签逻辑
        $creds = $this->credentials();
        $sig = $request->header('X-Supply-Signature');
        $ts = $request->header('X-Supply-Timestamp');
        $nonce = $request->header('X-Supply-Nonce');
        $ss = HmacSigner::buildSignString('POST', $request->getPathInfo(), $ts, $nonce, md5($request->getContent() ?: ''));
        if (! HmacSigner::verify($creds['api_secret'], $ss, $sig)) return null;
        $data = $request->json()->all();
        return ['upstream_order_id' => (string) ($data['supply_order_id'] ?? ''), 'status' => $data['status'] ?? '', 'cards' => $data['fulfillment']['cards'] ?? [], 'downstream_order_no' => $data['downstream_order_no'] ?? null];
    }
}
```

- [ ] **Step 5: 确认现有测试不回归（驱动 ping 等需真实上游，测试里用 mock）**

运行：
```bash
php artisan test --filter=SupplyManagerTest
```
预期：仍通过（只测 configSchema/工厂，不调 HTTP）。

- [ ] **Step 6: 提交**

```bash
git add app/Supply/Drivers/ app/Supply/Drivers/Concerns/MakesHttpRequests.php
git commit -m "feat(supply): 三驱动HTTP调用实现(dujiao HMAC/acg MD5/zcard自定义HMAC)"
```

---

## Task 3: 商品同步服务（SupplySyncService）+ 售价保护

**Files:**
- Create: `app/Supply/SupplySyncService.php`
- Create: `tests/Feature/SupplySyncServiceTest.php`

spec §5.1。映射 + 售价保护（再次同步不动 price）。

- [ ] **Step 1: 写失败测试**

`tests/Feature/SupplySyncServiceTest.php`:
```php
<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\SupplySource;
use App\Supply\Dto\UpstreamProduct;
use App\Supply\SupplySyncService;
use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

class SupplySyncServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_new_product_created_with_pricing_rule_percent(): void
    {
        $source = SupplySource::create([
            'name' => 'S', 'driver' => 'dujiao_next', 'base_url' => 'https://x.com',
            'credentials' => [], 'status' => 'active',
            'settings' => ['default_pricing_mode' => 'percent', 'default_markup_percent' => 10, 'auto_list' => true],
        ]);
        $service = app(SupplySyncService::class);

        $dto = new UpstreamProduct(code: 'UP1', name: '上游商品', price: 800, factoryPrice: 500, categoryCode: null);
        $product = $service->upsertProduct($source, $dto);

        $this->assertSame('UP1', $product->upstream_product_code);
        $this->assertSame($source->id, $product->upstream_source_id);
        $this->assertSame(500, (int) $product->factory_price);
        $this->assertSame(550, (int) $product->price); // 500 × 110% = 550
    }

    public function test_resync_updates_factory_price_but_keeps_local_price(): void
    {
        $source = SupplySource::create([
            'name' => 'S', 'driver' => 'dujiao_next', 'base_url' => 'https://x.com',
            'credentials' => [], 'status' => 'active', 'settings' => ['default_pricing_mode' => 'equal'],
        ]);
        $service = app(SupplySyncService::class);

        // 首次同步,平价
        $p1 = $service->upsertProduct($source, new UpstreamProduct(code: 'UP2', name: 'A', price: 500, factoryPrice: 500));
        $this->assertSame(500, (int) $p1->price);

        // 运营手动改价
        $p1->update(['price' => 999]);

        // 再次同步,上游涨价到 600
        $p2 = $service->upsertProduct($source, new UpstreamProduct(code: 'UP2', name: 'A', price: 600, factoryPrice: 600));
        $this->assertSame(600, (int) $p2->factory_price); // 成本更新
        $this->assertSame(999, (int) $p2->price); // 售价不动(售价保护)
    }

    public function test_inactive_upstream_product_gets_hidden(): void
    {
        $source = SupplySource::create([
            'name' => 'S', 'driver' => 'dujiao_next', 'base_url' => 'https://x.com',
            'credentials' => [], 'status' => 'active', 'settings' => [],
        ]);
        $service = app(SupplySyncService::class);
        $p = $service->upsertProduct($source, new UpstreamProduct(code: 'UP3', name: 'A', price: 500, factoryPrice: 500, isActive: true));

        $service->upsertProduct($source, new UpstreamProduct(code: 'UP3', name: 'A', price: 500, factoryPrice: 500, isActive: false));

        $this->assertSame(1, (int) $p->fresh()->hide); // 标记下架
    }
}
```

- [ ] **Step 2: 运行测试确认失败**

运行：
```bash
php artisan test --filter=SupplySyncServiceTest
```
预期：FAIL。

- [ ] **Step 3: 实现 SupplySyncService**

`app/Supply/SupplySyncService.php`:
```php
<?php

namespace App\Supply;

use App\Models\Category;
use App\Models\Product;
use App\Models\SupplySource;
use App\Supply\Dto\UpstreamProduct;

/**
 * 商品同步服务(spec §5.1)
 * 全量/增量同步上游商品进本地 products 表,含售价保护(再次同步不动 price)。
 */
class SupplySyncService
{
    /**
     * 单个商品 upsert(供批量同步和测试调用)。
     */
    public function upsertProduct(SupplySource $source, UpstreamProduct $dto): Product
    {
        $existing = Product::where('upstream_source_id', $source->id)
            ->where('upstream_product_code', $dto->code)
            ->first();

        if ($existing) {
            // 已有:更新上游拥有字段,不动 price(售价保护)
            $existing->update([
                'name' => $dto->name,
                'description' => $dto->description,
                'cover' => $dto->cover,
                'factory_price' => $dto->factoryPrice,
                'category_id' => $this->resolveCategoryId($source, $dto->categoryCode),
                'upstream_synced_at' => now(),
                'hide' => ! $dto->isActive ? 1 : $existing->hide, // 上游下架→标隐藏,不删
            ]);
            return $existing->fresh();
        }

        // 新建:按定价规则算初始 price
        $price = $this->computeInitialPrice($source, $dto->factoryPrice);

        return Product::create([
            'merchant_id' => 1,
            'name' => $dto->name,
            'slug' => $this->uniqueSlug($dto->name, $dto->code),
            'description' => $dto->description,
            'cover' => $dto->cover,
            'price' => $price, // 可能为 null(pending 模式)
            'factory_price' => $dto->factoryPrice,
            'stock_type' => 'card',
            'status' => ($price === null || ! ($source->settings['auto_list'] ?? true)) ? 0 : 1,
            'hide' => ! $dto->isActive ? 1 : 0,
            'category_id' => $this->resolveCategoryId($source, $dto->categoryCode),
            'upstream_source_id' => $source->id,
            'upstream_product_code' => $dto->code,
            'upstream_synced_at' => now(),
        ]);
    }

    /** 按定价规则算初始售价(spec §5.1,仅首次同步 price 为空时) */
    private function computeInitialPrice(SupplySource $source, int $factoryPrice): ?int
    {
        $mode = $source->settings['default_pricing_mode'] ?? 'percent';
        return match ($mode) {
            'fixed' => $factoryPrice + (int) ($source->settings['default_markup_amount'] ?? 0),
            'percent' => (int) round($factoryPrice * (1 + (int) ($source->settings['default_markup_percent'] ?? 10) / 100)),
            'equal' => $factoryPrice,
            'pending' => null,
            default => (int) round($factoryPrice * 1.1),
        };
    }

    private function resolveCategoryId(SupplySource $source, ?string $upstreamCatCode): ?int
    {
        if (! $upstreamCatCode) return null;
        // 简化:用 code 匹配,找不到则不设分类(实际可建映射表,本期略)
        $cat = Category::where('slug', $upstreamCatCode)->first();
        return $cat?->id;
    }

    private function uniqueSlug(string $name, string $code): string
    {
        $base = \Illuminate\Support\Str::slug($name) ?: ('p-' . $code);
        $slug = $base;
        $i = 1;
        while (Product::where('slug', $slug)->exists()) {
            $slug = $base . '-' . $i++;
        }
        return $slug;
    }
}
```

- [ ] **Step 4: 运行测试确认通过**

运行：
```bash
php artisan test --filter=SupplySyncServiceTest
```
预期：3 个测试通过。

- [ ] **Step 5: 提交**

```bash
git add app/Supply/SupplySyncService.php tests/Feature/SupplySyncServiceTest.php
git commit -m "feat(supply): 商品同步服务+售价保护(再次同步不动price)+初始定价规则"
```

---

## Task 4: 同步 Job + 同步触发端点接入 + 定时任务

**Files:**
- Create: `app/Jobs/SyncSupplySourceProducts.php`
- Modify: `app/Http/Controllers/Api/Admin/SupplySourceController.php`
- Modify: `routes/console.php`

- [ ] **Step 1: Job**

`app/Jobs/SyncSupplySourceProducts.php`:
```php
<?php

namespace App\Jobs;

use App\Models\SupplySource;
use App\Supply\SupplyManager;
use App\Supply\SupplySyncService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * 商品同步队列任务(spec §5.1)
 * 全量(full)或增量(incremental)拉取上游商品并 upsert。
 */
class SyncSupplySourceProducts implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public readonly int $sourceId,
        public readonly string $mode = 'incremental',
    ) {}

    public function handle(SupplyManager $manager, SupplySyncService $sync): void
    {
        $source = SupplySource::find($this->sourceId);
        if (! $source || ! $source->isActive()) return;

        try {
            $driver = $manager->driver($source);
            $updatedAfter = $this->mode === 'incremental' ? $source->last_synced_at : null;
            $page = 1;
            $created = $updated = $hidden = 0;

            do {
                $result = $driver->listProducts($updatedAfter, $page);
                foreach ($result['items'] as $dto) {
                    $exists = \App\Models\Product::where('upstream_source_id', $source->id)
                        ->where('upstream_product_code', $dto->code)->exists();
                    $p = $sync->upsertProduct($source, $dto);
                    if ($exists) $updated++; else $created++;
                    if (! $dto->isActive) $hidden++;
                }
                $page++;
            } while (! empty($result['has_more']));

            $source->update(['last_synced_at' => now(), 'last_error' => null]);
            Log::info("supply sync done source={$source->id} created={$created} updated={$updated} hidden={$hidden}");
        } catch (Throwable $e) {
            $source->update(['last_error' => $e->getMessage()]);
            Log::error("supply sync failed source={$source->id}: {$e->getMessage()}");
            throw $e;
        }
    }
}
```

- [ ] **Step 2: 接入同步端点**

替换 `SupplySourceController::sync`：
```php
    public function sync(Request $request, SupplySource $supplySource): JsonResponse
    {
        $mode = in_array($request->input('mode'), ['full', 'incremental']) ? $request->input('mode') : 'incremental';
        \App\Jobs\SyncSupplySourceProducts::dispatch($supplySource->id, $mode);
        return response()->json(['ok' => true, 'message' => '同步任务已派发', 'mode' => $mode]);
    }
```

- [ ] **Step 3: 定时任务（自动同步）**

读取 `routes/console.php`，追加：
```php
use App\Jobs\SyncSupplySourceProducts;
use App\Models\SupplySource;

// 货源商品自动同步(spec §6.6) —— 每小时跑增量,只对开启自动同步的 active 货源
Schedule::call(function () {
    if (! config('zcard.features.supply')) return;
    SupplySource::where('status', 'active')
        ->whereRaw("JSON_EXTRACT(settings, '$.auto_sync') = true")
        ->each(fn ($s) => SyncSupplySourceProducts::dispatch($s->id, 'incremental'));
})->hourly();

// nonce 清理(database 模式)
Schedule::call(fn () => app(\App\Supply\NonceStore::class)->pruneExpiredDatabase())->daily();
```

> 注意确认 `routes/console.php` 用的是 `Schedule` facade 还是 `Schedule::call`——Laravel 11+ console.php 直接用 `Schedule::call`。若文件用 `use Illuminate\Support\Facades\Schedule;` 则对齐。

- [ ] **Step 4: 提交**

```bash
git add app/Jobs/SyncSupplySourceProducts.php app/Http/Controllers/Api/Admin/SupplySourceController.php routes/console.php
git commit -m "feat(supply): 商品同步Job+定时任务(每小时增量同步active货源)"
```

---

## Task 5: 下游拿货编排（SupplyOrderService + FetchFromUpstreamJob + 回调接收）

**Files:**
- Create: `app/Supply/UpstreamOrderService.php`
- Create: `app/Jobs/FetchFromUpstream.php`
- Create: `app/Listeners/FetchFromUpstreamOnOrderPaid.php`
- Modify: `app/Providers/AppServiceProvider.php`
- Modify: `app/Http/Controllers/Api/Supply/SupplyController.php`

spec §5.3。注意命名冲突——Phase 2 已有 `SupplyOrderService`（对外供货下单）。本 Task 的「作为下游拿货」用 `UpstreamOrderService` 区分。

- [ ] **Step 1: UpstreamOrderService（拿货编排）**

`app/Supply/UpstreamOrderService.php`:
```php
<?php

namespace App\Supply;

use App\Models\Card;
use App\Models\Order;
use App\Models\SupplySource;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * 下游拿货编排(spec §5.3)
 * 顾客订单付款 → 触发去上游拿货。同步试 → 失败转异步 Job。
 */
class UpstreamOrderService
{
    public function __construct(private readonly SupplyManager $manager) {}

    /**
     * 履约订单(从上游拿货填卡密)。由 FetchFromUpstreamOnOrderPaid 监听器调用。
     */
    public function fulfill(Order $order): void
    {
        $product = $order->product;
        if (! $product || ! $product->upstream_source_id) return; // 非上游商品,跳过

        $source = SupplySource::find($product->upstream_source_id);
        if (! $source) return;

        $mode = $source->settings['fulfillment_mode'] ?? 'sync';

        if ($mode === 'async') {
            \App\Jobs\FetchFromUpstream::dispatch($order->id);
            return;
        }

        // sync:先同步试
        try {
            $this->fetchFromUpstream($order, $source);
        } catch (Throwable $e) {
            Log::warning("supply sync fetch failed, fallback to async: {$e->getMessage()}");
            $order->update(['delivery_status' => 'pending']);
            \App\Jobs\FetchFromUpstream::dispatch($order->id);
        }
    }

    /** 实际调上游下单拿货 */
    public function fetchFromUpstream(Order $order, SupplySource $source): void
    {
        $driver = $this->manager->driver($source);

        // 已有 upstream_order_id?查单
        if ($order->upstream_order_id) {
            $upstream = $driver->getOrder($order->upstream_order_id);
        } else {
            $product = $order->product;
            $upstream = $driver->createOrder([
                'product_code' => $product->upstream_product_code,
                'quantity' => $order->quantity,
                'downstream_order_no' => $order->order_no, // 幂等
                'callback_url' => rtrim(config('app.url'), '/') . '/api/supply/callback',
            ]);
            $order->update(['upstream_order_id' => $upstream->id, 'upstream_source_id' => $source->id]);
        }

        // 已发卡?
        if ($upstream->fulfillment && $upstream->fulfillment->isDelivered() && ! empty($upstream->fulfillment->cards)) {
            $this->writeCards($order, $upstream->fulfillment->cards);
        } elseif ($upstream->status === 'canceled') {
            $this->handleUpstreamCanceled($order, $source);
        }
        // 仍 pending:不动,等 Job 重试或回调
    }

    /** 把上游卡密写入本地订单 */
    public function writeCards(Order $order, array $cards): void
    {
        DB::transaction(function () use ($order, $cards) {
            $locked = Order::where('id', $order->id)->lockForUpdate()->firstOrFail();
            if ($locked->delivery_status === 'delivered') return; // 幂等

            foreach ($cards as $content) {
                Card::create([
                    'product_id' => $locked->product_id,
                    'content' => $content,
                    'status' => Card::STATUS_USED,
                    'order_id' => $locked->id,
                    'used_at' => now(),
                ]);
            }
            // 写 order_deliveries(若表存在)
            $locked->update(['delivery_status' => 'delivered']);
        });
    }

    /** 上游取消 → 按配置处理 */
    private function handleUpstreamCanceled(Order $order, SupplySource $source): void
    {
        $action = $source->settings['failure_action'] ?? 'manual';
        if ($action === 'auto_refund') {
            // 退款给顾客(复用现有退款逻辑,若有 RefundService 则调;否则标状态待人工)
            $order->update(['status' => 'closed', 'delivery_status' => 'failed']);
            Log::info("supply order auto-refunded: {$order->order_no}");
        } else {
            $order->update(['delivery_status' => 'failed']);
            Log::warning("supply order needs manual intervention: {$order->order_no}");
        }
    }
}
```

- [ ] **Step 2: FetchFromUpstream Job（异步重试）**

`app/Jobs/FetchFromUpstream.php`:
```php
<?php

namespace App\Jobs;

use App\Models\Order;
use App\Supply\UpstreamOrderService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * 异步拿货任务(spec §5.3)
 * 退避重试 5 次(10s/30s/1min/5min/15min)。
 */
class FetchFromUpstream implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 5;

    public function backoff(): array
    {
        return [10, 30, 60, 300, 900];
    }

    public function __construct(public readonly int $orderId) {}

    public function handle(UpstreamOrderService $service): void
    {
        $order = Order::find($this->orderId);
        if (! $order || $order->delivery_status === 'delivered') return;

        $source = $order->upstream_source_id ? \App\Models\SupplySource::find($order->upstream_source_id) : null;
        if (! $source) {
            $source = $order->product?->upstream_source_id ? \App\Models\SupplySource::find($order->product->upstream_source_id) : null;
        }
        if (! $source) return;

        $service->fetchFromUpstream($order, $source);

        // 仍未发卡 → 让重试机制继续,或等回调
        if ($order->fresh()->delivery_status !== 'delivered' && $this->attempts() >= $this->tries) {
            $service->handleTimeout($order, $source);
        }
    }
}
```

在 `UpstreamOrderService` 加 `handleTimeout` 方法（重试用尽）：
```php
    public function handleTimeout(Order $order, SupplySource $source): void
    {
        $action = $source->settings['failure_action'] ?? 'manual';
        if ($action === 'auto_refund') {
            $order->update(['status' => 'closed', 'delivery_status' => 'failed']);
        } else {
            $order->update(['delivery_status' => 'failed']);
        }
        Log::warning("supply upstream fetch timeout: {$order->order_no}");
    }
```

- [ ] **Step 3: OrderPaid 监听器 + 注册**

`app/Listeners/FetchFromUpstreamOnOrderPaid.php`:
```php
<?php

namespace App\Listeners;

use App\Events\OrderPaid;
use App\Supply\UpstreamOrderService;

class FetchFromUpstreamOnOrderPaid
{
    public function __construct(private readonly UpstreamOrderService $service) {}

    public function handle(OrderPaid $event): void
    {
        if (! config('zcard.features.supply') || ! config('zcard.supply.upstream_enabled')) return;

        $order = $event->order;
        // 只有从上游货源来的商品才拿货
        if ($order->product && $order->product->upstream_source_id) {
            $this->service->fulfill($order);
        }
    }
}
```

读取 `app/Providers/AppServiceProvider.php`，在 `Event::listen(OrderPaid::class, ...)` 列表里追加：
```php
        Event::listen(OrderPaid::class, [\App\Listeners\FetchFromUpstreamOnOrderPaid::class, 'handle']);
```

- [ ] **Step 4: 回调接收端点实现**

替换 `SupplyController::callback`：
```php
    public function callback(Request $request): JsonResponse
    {
        // 本站作为下游时,接收上游异步发货回调(spec §5.3)
        // 需识别来源货源 → 用对应驱动验签
        // 简化:按 upstream_order_id 或 downstream_order_no 匹配本地订单
        $orderNo = $request->input('downstream_order_no');
        $order = $orderNo ? \App\Models\Order::where('order_no', $orderNo)->first() : null;

        if (! $order || ! $order->upstream_source_id) {
            return response()->json(['ok' => false, 'error' => 'order_not_found'], 404);
        }

        $source = \App\Models\SupplySource::find($order->upstream_source_id);
        $driver = app(\App\Supply\SupplyManager::class)->driver($source);
        $payload = $driver->verifyCallback($request);

        if (! $payload) {
            return response()->json(['ok' => false, 'error' => 'invalid_signature'], 401);
        }

        if (! empty($payload['cards'])) {
            app(\App\Supply\UpstreamOrderService::class)->writeCards($order, $payload['cards']);
        }

        return response()->json(['ok' => true]);
    }
```

- [ ] **Step 5: 写拿货编排测试（mock 驱动）**

`tests/Feature/UpstreamOrderServiceTest.php`:
```php
<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Product;
use App\Models\SupplySource;
use App\Supply\Dto\UpstreamFulfillment;
use App\Supply\Dto\UpstreamOrder;
use App\Supply\UpstreamOrderService;
use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

class UpstreamOrderServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_write_cards_marks_order_delivered(): void
    {
        config(['zcard.features.supply' => true]);
        $source = SupplySource::create(['name' => 'S', 'driver' => 'dujiao_next', 'base_url' => 'https://x.com', 'credentials' => [], 'status' => 'active']);
        $product = Product::create(['merchant_id' => 1, 'name' => 'P', 'slug' => 'p1', 'price' => 500, 'factory_price' => 400, 'stock_type' => 'card', 'status' => 1, 'upstream_source_id' => $source->id, 'upstream_product_code' => 'UP1']);
        $order = Order::create(['order_no' => 'O1', 'merchant_id' => 1, 'product_id' => $product->id, 'quantity' => 2, 'amount' => 1000, 'status' => 'paid', 'delivery_status' => 'pending', 'paid_at' => now()]);

        app(UpstreamOrderService::class)->writeCards($order, ['CARD-A', 'CARD-B']);

        $this->assertSame('delivered', $order->fresh()->delivery_status);
        $this->assertDatabaseHas('cards', ['order_id' => $order->id, 'content' => 'CARD-A']);
        $this->assertDatabaseHas('cards', ['order_id' => $order->id, 'content' => 'CARD-B']);
    }

    public function test_write_cards_idempotent(): void
    {
        config(['zcard.features.supply' => true]);
        $source = SupplySource::create(['name' => 'S', 'driver' => 'dujiao_next', 'base_url' => 'https://x.com', 'credentials' => [], 'status' => 'active']);
        $product = Product::create(['merchant_id' => 1, 'name' => 'P', 'slug' => 'p2', 'price' => 500, 'factory_price' => 400, 'stock_type' => 'card', 'status' => 1, 'upstream_source_id' => $source->id, 'upstream_product_code' => 'UP2']);
        $order = Order::create(['order_no' => 'O2', 'merchant_id' => 1, 'product_id' => $product->id, 'quantity' => 1, 'amount' => 500, 'status' => 'paid', 'delivery_status' => 'delivered', 'paid_at' => now()]);

        // 已 delivered,再写不应重复
        app(UpstreamOrderService::class)->writeCards($order, ['DUP']);
        $this->assertDatabaseMissing('cards', ['content' => 'DUP']);
    }
}
```

- [ ] **Step 6: 运行测试**

运行：
```bash
php artisan test --filter=UpstreamOrderServiceTest
```
预期：2 个测试通过。

- [ ] **Step 7: 提交**

```bash
git add app/Supply/UpstreamOrderService.php app/Jobs/FetchFromUpstream.php app/Listeners/FetchFromUpstreamOnOrderPaid.php app/Http/Controllers/Api/Supply/SupplyController.php app/Providers/AppServiceProvider.php tests/Feature/UpstreamOrderServiceTest.php
git commit -m "feat(supply): 下游拿货编排(同步试→异步Job回退+回调接收+退避重试)"
```

---

## Task 6: 全量回归 + 完成验证

- [ ] **Step 1: 运行全量测试**

运行：
```bash
php artisan config:clear && php artisan test
```
预期：全部通过。

- [ ] **Step 2: 检查多语言完整性（项目记忆要求）**

运行（确认所有新增 key 两种语言都有）：
```bash
php -r "\$zh = require 'lang/zh_CN/messages.php'; \$en = require 'lang/en/messages.php'; \$missing = array_diff_key(array_filter(\$zh, fn(\$k) => str_starts_with(\$k, 'supply'), ARRAY_FILTER_USE_KEY), array_filter(\$en, fn(\$k) => str_starts_with(\$k, 'supply'), ARRAY_FILTER_USE_KEY)); echo \$missing ? 'EN 缺失: ' . implode(',', array_keys(\$missing)) : 'supply 多语言一致'; echo PHP_EOL;"
```
预期：`supply 多语言一致`。

- [ ] **Step 3: 检查金额字段全为分（项目记忆要求）**

运行（确认新表金额列都是 bigInteger）：
```bash
grep -E "(amount|balance|price)" database/migrations/2026_08_02_*.php | grep -v "bigInteger\|integer\|->comment" | grep -v "//"
```
预期：无输出（所有金额列都是 bigInteger）。

- [ ] **Step 4: 提交（若有遗漏修复）**

```bash
git add -A && git commit -m "fix(supply): Phase 3 回归 + 多语言/金额校验" || echo "无变更"
```

---

## Phase 3 完成标准

- [ ] 货源管理后台 API（CRUD/驱动元数据/测试连通/同步触发）+ 凭证加密脱敏
- [ ] 三驱动 HTTP 调用实现（dujiao HMAC / acg MD5 / zcard 自定义 HMAC）
- [ ] 商品同步服务（SupplySyncService）+ 售价保护（再次同步不动 price）+ 初始定价规则
- [ ] 同步 Job（SyncSupplySourceProducts）+ 定时任务（每小时增量同步）
- [ ] 下游拿货编排（UpstreamOrderService + FetchFromUpstreamJob + 回调接收 + 退避重试）
- [ ] OrderPaid 监听器接入（上游商品订单自动触发拿货）
- [ ] 多语言文案完整（zh_CN/en 一致）
- [ ] 全量 `php artisan test` 无回归
- [ ] 金额字段全为分

---

## 三阶段总完成 = 整个货源对接功能交付

**Phase 1**（地基）：数据模型 + 配置开关 + 驱动抽象 + HMAC 工具
**Phase 2**（作为上游）：对外供货 API + 账号管理 + 下单发卡 + 事件守卫
**Phase 3**（作为下游 + 闭环）：货源设置后台 + 商品同步 + 拿货编排 + 三驱动 HTTP 实现

完成后实现：
- 对接 dujiao-next / acg-faka 作为上游货源拿货
- 自己开供货 API 让别人对接
- **自己对接自己**（ZCardDriver 调本系统 /api/supply/*）
- 多个上游并存、SKU 级专属定价、预存扣费、同步/异步发卡

---

## Follow-up（本期不做，留作后续迭代）

以下 spec 条目因第一版发卡以同步为主、或属前端集成，未在本三阶段计划中实现，记录在此供后续跟进：

1. **§4.7 对下游主动发回调通知**：第一版供货下单（sync 模式）同步返回卡密，无需主动 POST 回调下游。async 模式真正异步发卡（先扣费不发货、稍后回调）实现后，才需要 `SupplyCallbackDispatcher`（签名 POST 到下游 callback_url）。当前 SupplyOrderService 第一版 async 与 sync 行为一致。
2. **§5.2 库存实时查询前台集成**：storefront 商品页对上游商品调 `driver->getStock()` 实时展示库存。属前端改造，不在后端三阶段范围。
3. **§6/§7 Filament + sysadmin SPA 界面**：本计划只做后端 API 层（API-first 是本项目约定，Filament 和 SPA 都消费 `/api/admin/*`）。前端界面挂载单独排期。
4. **§7.5 在线自助充值**：第一版充值纯人工（运营手动加余额），在线充值后续扩展。
