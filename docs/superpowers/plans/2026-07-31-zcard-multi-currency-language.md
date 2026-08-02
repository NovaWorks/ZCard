# 多货币 + 多语言 实施计划

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** 让 ZCard 支持「客户可切换显示货币」+「支付通道按目标货币换算收款」+「storefront 前台与后端 API 多语言」，全部基于现有「基础货币·分」存储不变。

**Architecture:** 单一基础货币（默认 CNY）为唯一真相源，分两层独立换算——显示层（客户浏览时按汇率换算展示，不改存储）与支付层（通道按 config 换算目标货币收款）。订单下单瞬间锁定汇率快照。借鉴 dujiao-next 的「通道 config 内 currency+rate + 驱动回报 amount_sent」模式，补足它缺失的客户可切换显示货币。

**Tech Stack:** Laravel 13（Eloquent migrations、Service 类、HTTP Middleware、`__()`/lang 文件）、bcmath、Vue 3 + vue-i18n（Composition API）+ Pinia、sysadmin 复用现有 vue-i18n。

**测试策略:** 后端用 PHPUnit Feature 测试（已配置 `phpunit.xml`，目录 `tests/Feature`、`tests/Unit`）。货币换算/中间件/驱动契约走 TDD。前端 i18n 改造以编译通过 + 人工/浏览器验证为准（项目无前端单测框架）。

**Spec:** `docs/superpowers/specs/2026-07-31-zcard-multi-currency-language-design.md`

---

## 文件结构总览

**新建（后端）**
- `database/migrations/2026_07_31_130010_create_currencies_table.php` — 货币字典表
- `database/migrations/2026_07_31_130020_add_currency_snapshot_to_orders_table.php` — 订单快照 4 列
- `database/migrations/2026_07_31_130030_add_charge_currency_to_payments_table.php` — 支付快照 3 列
- `database/seeders/CurrencySeeder.php` — 默认 CNY/USD/EUR 种子
- `app/Models/Currency.php` — 货币模型
- `app/Support/CurrencyService.php` — 换算/格式化/读汇率（含缓存）
- `app/Http/Middleware/ResolveDisplayCurrency.php` — 解析请求显示货币
- `app/Http/Middleware/SetLocale.php` — 解析 Accept-Language 设 locale
- `app/Http/Controllers/Api/CurrencyController.php` — 公开货币列表端点
- `app/Http/Controllers/Api/Admin/CurrencyController.php` — 后台货币 CRUD
- `lang/zh_CN/messages.php` + `lang/en/messages.php` — 应用字符串

**改造（后端）**
- `app/Support/StorefrontConfig.php` — defaults 新增 4 key
- `app/Payment/Contracts/PaymentDriver.php` — 契约加 `getSupportedCurrencies()`
- `app/Payment/PaymentResult.php` — 增加 currency/amount_sent 字段
- `app/Payment/Drivers/*.php` — 8 个驱动适配
- `app/Support/PaymentService.php` — 通道换算 + payments 写快照列
- `app/Support/OrderService.php` — createOrder 写订单快照列
- `app/Http/Controllers/Api/OrderController.php` / `ProductController.php` — 响应加 display 字段
- `routes/api.php` — 注册中间件 + 货币路由 + 语言路由
- `bootstrap/app.php` — 别名 CurrencyService

**新建（storefront 前端）**
- `storefront/src/utils/money.ts` — 统一格式化
- `storefront/src/locales/index.ts` + `langs/zh.json` + `langs/en.json` — i18n
- `storefront/src/stores/preferences.ts` — 货币/语言状态
- `storefront/src/api/currency.ts` + `storefront/src/api/language.ts`

**改造（storefront 前端）** ~10 个 .vue 文件 + `main.ts` + `api/products.ts`/`orders.ts`

**改造（sysadmin）** 货币管理页 + settings tab + 语言 enum 扩展

---

# 阶段一 · 货币基础设施

## Task 1: 创建 currencies 表 + 模型

**Files:**
- Create: `database/migrations/2026_07_31_130010_create_currencies_table.php`
- Create: `app/Models/Currency.php`
- Test: `tests/Feature/CurrencyModelTest.php`

- [ ] **Step 1: 写迁移**

`database/migrations/2026_07_31_130010_create_currencies_table.php`:
```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('currencies', function (Blueprint $table) {
            $table->char('code', 3)->primary();
            $table->string('name');
            $table->string('symbol', 10);
            $table->enum('symbol_position', ['before', 'after'])->default('before');
            $table->unsignedTinyInteger('decimal_places')->default(2);
            $table->decimal('exchange_rate', 20, 8)->default(1);
            $table->boolean('is_base')->default(false);
            $table->boolean('is_enabled')->default(false);
            $table->integer('sort')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('currencies');
    }
};
```

- [ ] **Step 2: 写模型**

`app/Models/Currency.php`:
```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * 货币字典(spec §2.1)。code 为主键;is_base 全局唯一。
 */
class Currency extends Model
{
    public $incrementing = false;
    protected $keyType = 'string';
    protected $primaryKey = 'code';

    protected $fillable = [
        'code', 'name', 'symbol', 'symbol_position', 'decimal_places',
        'exchange_rate', 'is_base', 'is_enabled', 'sort',
    ];

    protected $casts = [
        'decimal_places' => 'integer',
        'exchange_rate' => 'decimal:8',
        'is_base' => 'boolean',
        'is_enabled' => 'boolean',
        'sort' => 'integer',
    ];
}
```

- [ ] **Step 3: 写测试**

`tests/Feature/CurrencyModelTest.php`:
```php
<?php

namespace Tests\Feature;

use App\Models\Currency;
use Tests\TestCase;

class CurrencyModelTest extends TestCase
{
    public function test_currency_uses_string_code_primary_key(): void
    {
        $c = Currency::create([
            'code' => 'USD', 'name' => '美元', 'symbol' => '$',
            'symbol_position' => 'before', 'decimal_places' => 2,
            'exchange_rate' => '0.14000000', 'is_base' => false, 'is_enabled' => true, 'sort' => 1,
        ]);
        $this->assertSame('USD', $c->code);
        $this->assertSame('USD', $c->fresh()->code); // string PK 持久化
        $this->assertFalse($c->fresh()->is_base);
    }
}
```

- [ ] **Step 4: 跑迁移和测试**

```bash
php artisan migrate
php artisan test tests/Feature/CurrencyModelTest.php
```
Expected: migrate 成功；测试 PASS。

- [ ] **Step 5: Commit**

```bash
git add database/migrations/2026_07_31_130010_create_currencies_table.php app/Models/Currency.php tests/Feature/CurrencyModelTest.php
git commit -m "feat: currencies table + model"
```

---

## Task 2: CurrencySeeder + 配置 StorefrontConfig 货币 key

**Files:**
- Create: `database/seeders/CurrencySeeder.php`
- Modify: `database/seeders/DatabaseSeeder.php`（调用新 seeder，如存在）
- Modify: `app/Support/StorefrontConfig.php`（`defaults()` 内新增 4 key）

- [ ] **Step 1: 写 Seeder**

`database/seeders/CurrencySeeder.php`:
```php
<?php

namespace Database\Seeders;

use App\Models\Currency;
use Illuminate\Database\Seeder;

class CurrencySeeder extends Seeder
{
    public function run(): void
    {
        $rows = [
            ['code' => 'CNY', 'name' => '人民币', 'symbol' => '¥', 'symbol_position' => 'before', 'decimal_places' => 2, 'exchange_rate' => '1', 'is_base' => true,  'is_enabled' => true, 'sort' => 0],
            ['code' => 'USD', 'name' => '美元',   'symbol' => '$', 'symbol_position' => 'before', 'decimal_places' => 2, 'exchange_rate' => '0.14000000', 'is_base' => false, 'is_enabled' => false, 'sort' => 1],
            ['code' => 'EUR', 'name' => '欧元',   'symbol' => '€', 'symbol_position' => 'after',  'decimal_places' => 2, 'exchange_rate' => '0.13000000', 'is_base' => false, 'is_enabled' => false, 'sort' => 2],
        ];
        foreach ($rows as $r) {
            Currency::updateOrCreate(['code' => $r['code']], $r);
        }
    }
}
```

- [ ] **Step 2: 在 StorefrontConfig::defaults() 末尾追加货币/语言配置 key**

在 `app/Support/StorefrontConfig.php` 的 `defaults()` 返回数组末尾（`'cash_type_usdt' => true,` 之后）加：
```php
            // 多货币与多语言
            'base_currency' => 'CNY',
            'default_display_currency' => 'CNY',
            'enabled_languages' => ['zh'],
            'default_language' => 'zh',
```

- [ ] **Step 3: 注册 seeder**

检查 `database/seeders/DatabaseSeeder.php` 是否存在；若存在，在 `$this->call([...])` 内追加 `CurrencySeeder::class`。若不存在，跳过（手动 `php artisan db:seed --class=CurrencySeeder`）。

- [ ] **Step 4: 跑 seeder 并验证**

```bash
php artisan db:seed --class=CurrencySeeder
php artisan tinker --execute="echo App\\Models\\Currency::count();"   # 期望 3
```
Expected: 输出 `3`。

- [ ] **Step 5: Commit**

```bash
git add database/seeders/CurrencySeeder.php app/Support/StorefrontConfig.php database/seeders/DatabaseSeeder.php
git commit -m "feat: currency seeder + storefront config keys"
```

---

## Task 3: CurrencyService（换算/格式化/读汇率，含缓存）— TDD

**Files:**
- Create: `app/Support/CurrencyService.php`
- Test: `tests/Unit/CurrencyServiceTest.php`

- [ ] **Step 1: 写失败测试**

`tests/Unit/CurrencyServiceTest.php`:
```php
<?php

namespace Tests\Unit;

use App\Models\Currency;
use App\Support\CurrencyService;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class CurrencyServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Currency::query()->delete();
        Currency::create(['code'=>'CNY','name'=>'人民币','symbol'=>'¥','symbol_position'=>'before','decimal_places'=>2,'exchange_rate'=>'1','is_base'=>true,'is_enabled'=>true,'sort'=>0]);
        Currency::create(['code'=>'USD','name'=>'美元','symbol'=>'$','symbol_position'=>'before','decimal_places'=>2,'exchange_rate'=>'0.14000000','is_base'=>false,'is_enabled'=>true,'sort'=>1]);
    }

    public function test_convert_base_to_usd(): void
    {
        $svc = app(CurrencyService::class);
        // 1250 分 = 12.50 CNY × 0.14 = 1.75 USD = 175 分
        $r = $svc->convert(1250, 'USD');
        $this->assertSame(175, $r['amount']);
        $this->assertSame('0.14000000', $r['rate']);
        $this->assertSame('USD', $r['currency']);
    }

    public function test_convert_to_base_returns_same(): void
    {
        $svc = app(CurrencyService::class);
        $r = $svc->convert(1250, 'CNY');
        $this->assertSame(1250, $r['amount']);
        $this->assertSame('1', $r['rate']);
    }

    public function test_format_symbol_before(): void
    {
        $svc = app(CurrencyService::class);
        $this->assertSame('¥12.50', $svc->format(1250, 'CNY'));
        $this->assertSame('$1.75', $svc->format(175, 'USD'));
    }

    public function test_get_enabled_caches_results(): void
    {
        $svc = app(CurrencyService::class);
        $svc->getEnabledCurrencies();
        $this->assertTrue(Cache::has(CurrencyService::CACHE_KEY));
    }
}
```

- [ ] **Step 2: 跑测试确认失败**

```bash
php artisan test tests/Unit/CurrencyServiceTest.php
```
Expected: FAIL（类不存在）。

- [ ] **Step 3: 写实现**

`app/Support/CurrencyService.php`:
```php
<?php

namespace App\Support;

use App\Models\Currency;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * 货币换算/格式化服务(spec §3.1)。
 * 基础金额(分) × exchange_rate = 目标金额(分)。
 */
class CurrencyService
{
    public const CACHE_KEY = 'currencies:enabled';
    public const CACHE_TTL = 3600;

    /** 基础货币 code(来自 StorefrontConfig,默认 CNY) */
    public function getBaseCurrency(): string
    {
        return (string) (StorefrontConfig::get('base_currency') ?? 'CNY');
    }

    /** 启用货币集合(带缓存)。 */
    public function getEnabledCurrencies(): Collection
    {
        return Cache::remember(self::CACHE_KEY, self::CACHE_TTL, function () {
            // 基础货币始终包含(即使误关)
            return Currency::where('is_enabled', true)
                ->orWhere('is_base', true)
                ->orderBy('sort')
                ->get();
        });
    }

    public function getCurrency(string $code): ?Currency
    {
        return $this->getEnabledCurrencies()->firstWhere('code', strtoupper($code));
    }

    /**
     * 基础金额(分) → 目标货币金额(分) + 汇率。
     * 返回 ['amount'=>int, 'rate'=>string, 'currency'=>string]
     */
    public function convert(int $baseFen, string $toCurrency): array
    {
        $cur = $this->getCurrency($toCurrency);
        if (! $cur) {
            return ['amount' => $baseFen, 'rate' => '1', 'currency' => $this->getBaseCurrency()];
        }
        // 分 → 元 × rate → 元 → 分(按 decimal_places 取整)
        $yuan = bcdiv((string) $baseFen, '100', 8);
        $convertedYuan = bcmul($yuan, (string) $cur->exchange_rate, 8);
        // 最小单位 = 元 × 10^decimal_places
        $minUnit = bcpow('10', (string) $cur->decimal_places);
        $amountMin = bcmul($convertedYuan, $minUnit, 0); // 截断到整数分
        return [
            'amount' => (int) $amountMin,
            'rate' => (string) $cur->exchange_rate,
            'currency' => $cur->code,
        ];
    }

    /** 格式化最小单位为带符号字符串,如 "¥12.50" */
    public function format(int $minUnit, string $currency): string
    {
        $cur = $this->getCurrency($currency);
        if (! $cur) {
            return (string) ($minUnit / 100);
        }
        $minUnit = (string) $minUnit;
        $divisor = bcpow('10', (string) $cur->decimal_places);
        $value = bcdiv($minUnit, $divisor, $cur->decimal_places);
        $formatted = $cur->symbol_position === 'before'
            ? $cur->symbol . $value
            : $value . $cur->symbol;
        return $formatted;
    }

    /** 清缓存(管理员改汇率后调用) */
    public function flushCache(): void
    {
        Cache::forget(self::CACHE_KEY);
    }
}
```

- [ ] **Step 4: 注册服务别名**

在 `bootstrap/app.php` 中（找到 `$applications->registered` 或在闭包内），加入：
```php
$app->alias(\App\Support\CurrencyService::class, 'currency');
```
（Laravel 自动解析可注入；别名便于 `app('currency')` 调用。若该行形式不符项目结构，改为在 `app/Providers/AppServiceProvider.php` 的 `register()` 内 `$this->app->singleton('currency', fn () => new CurrencyService());`。）

- [ ] **Step 5: 跑测试确认通过**

```bash
php artisan test tests/Unit/CurrencyServiceTest.php
```
Expected: 4 个测试 PASS。

- [ ] **Step 6: Commit**

```bash
git add app/Support/CurrencyService.php tests/Unit/CurrencyServiceTest.php bootstrap/app.php
git commit -m "feat: CurrencyService conversion + formatting"
```

---

## Task 4: 订单快照迁移 + createOrder 写入快照

**Files:**
- Create: `database/migrations/2026_07_31_130020_add_currency_snapshot_to_orders_table.php`
- Modify: `app/Models/Order.php`（fillable + casts）
- Modify: `app/Support/OrderService.php`（createOrder 末尾 + 入参）
- Modify: `app/Http/Controllers/Api/OrderController.php`（create 传 displayCurrency）

- [ ] **Step 1: 写迁移**

`database/migrations/2026_07_31_130020_add_currency_snapshot_to_orders_table.php`:
```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->char('base_currency', 3)->nullable()->after('amount');
            $table->char('display_currency', 3)->nullable()->after('base_currency');
            $table->decimal('exchange_rate', 20, 8)->nullable()->after('display_currency');
            $table->bigInteger('amount_display')->nullable()->comment('显示货币·最小单位')->after('exchange_rate');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['base_currency', 'display_currency', 'exchange_rate', 'amount_display']);
        });
    }
};
```

- [ ] **Step 2: 更新 Order 模型 fillable/casts**

在 `app/Models/Order.php` 的 `$fillable` 数组加入 `'base_currency', 'display_currency', 'exchange_rate', 'amount_display'`，并在 `$casts` 加入：
```php
        'exchange_rate' => 'decimal:8',
```

- [ ] **Step 3: 改 OrderService::createOrder 接收 displayCurrency 并写快照**

在 `app/Support/OrderService.php`，把 `createOrder` 方法签名加可选参数：
```php
    public function createOrder(int $productId, ?int $skuId, int $qty, array $customer): Order
```
改为：
```php
    public function createOrder(int $productId, ?int $skuId, int $qty, array $customer, ?string $displayCurrency = null): Order
```

在 `Order::create([...])` 调用前（DB::transaction 内，计算好 `$amount` 之后），插入汇率快照计算：
```php
            // 货币快照(spec §3.5):下单瞬间锁定显示汇率
            $currencySvc = app(\App\Support\CurrencyService::class);
            $baseCur = $currencySvc->getBaseCurrency();
            $dispCur = $displayCurrency ?: $baseCur;
            $conv = $currencySvc->convert((int) $amount, $dispCur);
```
然后在 `Order::create([...])` 的数组里追加：
```php
                'base_currency' => $baseCur,
                'display_currency' => $conv['currency'],
                'exchange_rate' => $conv['rate'],
                'amount_display' => $conv['amount'],
```

- [ ] **Step 4: 改 OrderController::create 传 displayCurrency**

在 `app/Http/Controllers/Api/OrderController.php` 的 `create()` 方法 `$data = $request->validate([...])` 增加：
```php
            'display_currency' => 'nullable|string|size:3',
```
然后把 `$service->createOrder($data['product_id'], ...)` 调用追加第 5 参：
```php
                $data['display_currency'] ?? null,
```

- [ ] **Step 5: 跑迁移并验证下单**

```bash
php artisan migrate
```
手工或写一个 feature 测试创建订单后断言 `display_currency`/`amount_display` 已写入（参考现有订单测试模式；若无现成测试，至少 `php artisan tinker` 创建一笔订单检查列有值）。

- [ ] **Step 6: Commit**

```bash
git add database/migrations/2026_07_31_130020_add_currency_snapshot_to_orders_table.php app/Models/Order.php app/Support/OrderService.php app/Http/Controllers/Api/OrderController.php
git commit -m "feat: order currency snapshot on checkout"
```

---

## Task 5: ResolveDisplayCurrency 中间件 + 公开货币列表端点

**Files:**
- Create: `app/Http/Middleware/ResolveDisplayCurrency.php`
- Create: `app/Http/Controllers/Api/CurrencyController.php`
- Modify: `routes/api.php`（中间件 + 货币路由）
- Modify: `app/Http/Kernel.php` 或 `bootstrap/app.php`（注册中间件别名）

- [ ] **Step 1: 写中间件**

`app/Http/Middleware/ResolveDisplayCurrency.php`:
```php
<?php

namespace App\Http\Middleware;

use App\Support\CurrencyService;
use Closure;
use Illuminate\Http\Request;

/**
 * 解析当前请求的显示货币(spec §3.2):X-Currency 头 > ?currency= > 默认。
 * 写入 request attribute 'currency'。
 */
class ResolveDisplayCurrency
{
    public function handle(Request $request, Closure $next): mixed
    {
        $svc = app(CurrencyService::class);
        $code = $request->header('X-Currency')
            ?: $request->query('currency')
            ?: $svc->getBaseCurrency();
        $code = strtoupper(trim($code));

        // 非启用货币则回退基础货币
        if (! $svc->getCurrency($code)) {
            $code = $svc->getBaseCurrency();
        }
        $request->attributes->set('currency', $code);
        return $next($request);
    }
}
```

- [ ] **Step 2: 注册中间件别名**

在 `bootstrap/app.php` 的 `->withMiddleware()` 闭包内追加 alias（参考现有中间件注册形式）：
```php
        $middleware->alias([
            'display.currency' => \App\Http\Middleware\ResolveDisplayCurrency::class,
        ]);
```
（若项目用 `app/Http/Kernel.php` 注册 alias，则在该文件 `$routeAliases`/`$middlewareAliases` 内追加。先读 `bootstrap/app.php` 确认项目用的是哪种。）

- [ ] **Step 3: 写货币列表控制器**

`app/Http/Controllers/Api/CurrencyController.php`:
```php
<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\CurrencyService;
use Illuminate\Http\JsonResponse;

class CurrencyController extends Controller
{
    /** 启用货币列表(供前台货币切换器) */
    public function index(CurrencyService $svc): JsonResponse
    {
        $base = $svc->getBaseCurrency();
        $list = $svc->getEnabledCurrencies()->map(fn ($c) => [
            'code' => $c->code,
            'name' => $c->name,
            'symbol' => $c->symbol,
            'symbol_position' => $c->symbol_position,
            'decimal_places' => $c->decimal_places,
            'is_base' => $c->is_base,
        ])->values();

        return response()->json([
            'base_currency' => $base,
            'currencies' => $list,
        ]);
    }
}
```

- [ ] **Step 4: 注册路由 + 给 storefront API 组套中间件**

在 `routes/api.php` 顶部 storefront 公开路由区（`Route::get('/health', ...)` 附近）加入货币端点，并把商品/订单/设置公开路由包进中间件组。新增：
```php
use App\Http\Controllers\Api\CurrencyController;
// ...
Route::get('/currencies', [CurrencyController::class, 'index'])->name('api.currencies.index');

Route::middleware('display.currency')->group(function () {
    // 商品、订单、店铺设置等公开 storefront 端点放这里(把这些现有 Route 移进来)
});
```
注意：先读取现有 `routes/api.php`，把 storefront 公开商品/订单/设置路由移入 `display.currency` 中间件组；admin 组不动。

- [ ] **Step 5: 验证端点**

```bash
php artisan route:list --path=api/currencies   # 期望列出 GET api/currencies
curl -s http://localhost:8000/api/currencies | head   # 期望返回 JSON(按实际域/端口调整)
```

- [ ] **Step 6: Commit**

```bash
git add app/Http/Middleware/ResolveDisplayCurrency.php app/Http/Controllers/Api/CurrencyController.php routes/api.php bootstrap/app.php
git commit -m "feat: display-currency middleware + public currency list endpoint"
```

---

## Task 6: 商品/订单 API 响应注入 display 字段

**Files:**
- Modify: `app/Http/Controllers/Api/ProductController.php`（`transform()` 注入 display）
- Modify: `app/Http/Controllers/Api/OrderController.php`（create/myOrders/query 响应注入）
- Modify: `app/Support/OrderService.php`（myOrders/searchOrders/getOrderDetail 返回注入）
- Test: `tests/Feature/DisplayCurrencyResponseTest.php`

- [ ] **Step 1: 写失败测试**

`tests/Feature/DisplayCurrencyResponseTest.php`:
```php
<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DisplayCurrencyResponseTest extends TestCase
{
    use RefreshDatabase;

    public function test_product_list_has_display_currency_fields(): void
    {
        $cat = Category::factory()->create();
        $p = Product::factory()->create(['category_id' => $cat->id, 'price' => 1250, 'stock' => 5]);

        $resp = $this->withHeaders(['X-Currency' => 'USD'])->getJson('/api/products');

        $resp->assertOk();
        $item = collect($resp->json('data'))->firstWhere('id', $p->id);
        $this->assertNotNull($item);
        $this->assertSame(1250, $item['price_base']);     // 基础货币分不变
        $this->assertSame('USD', $item['display_currency']);
        $this->assertSame(175, $item['price_display']);   // 12.50 × 0.14 = 1.75
    }
}
```
（若 `Product::factory()`/`Category::factory()` 不存在，先确认 factories；若项目无 factories，改用直接 `Product::create([...])` 构造最小数据。）

- [ ] **Step 2: 跑测试确认失败**

```bash
php artisan test tests/Feature/DisplayCurrencyResponseTest.php
```
Expected: FAIL（无 price_base/display_currency 字段）。

- [ ] **Step 3: 改 ProductController::transform 注入 display 字段**

在 `app/Http/Controllers/Api/ProductController.php` 的 `transform()` 方法签名加注入 `CurrencyService`，并在 `price` 处替换为 base+display。把：
```php
        $data = [
            'id' => $p->id,
            'name' => $p->name,
            'slug' => $p->slug,
            'cover' => $p->cover,
            'price' => (int) $p->price,
```
改为：
```php
        $svc = app(\App\Support\CurrencyService::class);
        $cur = request()->attributes->get('currency') ?? $svc->getBaseCurrency();
        $conv = $svc->convert((int) $p->price, $cur);
        $data = [
            'id' => $p->id,
            'name' => $p->name,
            'slug' => $p->slug,
            'cover' => $p->cover,
            'price_base' => (int) $p->price,
            'price_display' => $conv['amount'],
            'display_currency' => $conv['currency'],
            'exchange_rate' => $conv['rate'],
```
对 detail 分支里的 sku `'price'` 与 `'member_price'` 同样补 `price_base`/`price_display`（member_price 是 map，对每个 level 做 convert）。

- [ ] **Step 4: 改订单相关响应注入**

`OrderController::create` 的返回数组：把 `'amount' => $order->amount,` 扩展为同时返回基础 + display（订单已有快照列，直接读）：
```php
            return response()->json([
                'order_no' => $order->order_no,
                'amount' => $order->amount,
                'amount_base' => $order->amount,
                'amount_display' => $order->amount_display,
                'display_currency' => $order->display_currency,
                'exchange_rate' => $order->exchange_rate,
                'status' => $order->status,
            ], 201);
```
`OrderService::myOrders`/`searchOrders`/`getOrderDetail` 的每个返回项里 `amount` 旁补 `amount_display`/`display_currency`/`exchange_rate`（读订单行快照列）。逐一加字段。

- [ ] **Step 5: 跑测试确认通过 + 现有测试不破**

```bash
php artisan test
```
Expected: 新测试 PASS，且不破坏既有测试（注意现有测试若断言了旧的 `price` 字段存在，需保留 `price` 兼容字段——本步骤保留 `price`/`amount` 旧字段，只新增 base/display 字段）。

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/Api/ProductController.php app/Http/Controllers/Api/OrderController.php app/Support/OrderService.php tests/Feature/DisplayCurrencyResponseTest.php
git commit -m "feat: inject display currency into product/order API responses"
```

---

## Task 7: 后端 API 多语言基础（SetLocale 中间件 + lang 文件 + 首批 __()）

**Files:**
- Create: `app/Http/Middleware/SetLocale.php`
- Create: `lang/zh_CN/messages.php`、`lang/en/messages.php`
- Modify: `bootstrap/app.php`（注册中间件）
- Modify: `routes/api.php`（套中间件）
- Modify: 首批控制器硬编码中文（OrderController 的 message）→ `__()`

- [ ] **Step 1: 写 lang 文件**

`lang/zh_CN/messages.php`:
```php
<?php

return [
    'guest_only' => '当前仅限会员下单,请先登录',
    'captcha_error' => '验证码错误',
    'order_not_found' => '未找到相关订单',
    'insufficient_stock' => '库存不足,需要 :need 张,仅剩 :have 张',
];
```

`lang/en/messages.php`:
```php
<?php

return [
    'guest_only' => 'Only members can checkout. Please log in first.',
    'captcha_error' => 'Invalid captcha.',
    'order_not_found' => 'No matching orders found.',
    'insufficient_stock' => 'Insufficient stock: :need needed, only :have left.',
];
```

- [ ] **Step 2: 写 SetLocale 中间件**

`app/Http/Middleware/SetLocale.php`:
```php
<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;

/**
 * 解析 Accept-Language 设 App locale(spec §4.2)。
 * 支持 zh/zh-CN/zh-CN,zh;q=0.9 → zh_CN;en → en。
 */
class SetLocale
{
    public function handle(Request $request, Closure $next): mixed
    {
        $header = $request->header('Accept-Language', '');
        $locale = 'zh_CN'; // 默认
        if (stripos($header, 'en') === 0 || preg_match('/(^|,\s*)en/i', $header) && !stripos($header, 'zh')) {
            $locale = 'en';
        }
        // X-Lang 头优先(供前端显式传)
        if ($lang = $request->header('X-Lang')) {
            $locale = strtolower($lang) === 'en' ? 'en' : 'zh_CN';
        }
        App::setLocale($locale);
        return $next($request);
    }
}
```

- [ ] **Step 3: 注册中间件别名 + 套到 storefront 组**

在 `bootstrap/app.php` 的 alias 内追加：
```php
            'set.locale' => \App\Http\Middleware\SetLocale::class,
```
在 `routes/api.php`，把 Task 5 的 `display.currency` 组改成同时含 `set.locale`：
```php
Route::middleware(['display.currency', 'set.locale'])->group(function () {
```

- [ ] **Step 4: 改 OrderController 首批硬编码中文**

`OrderController::create` 内：
```php
            return response()->json(['message' => '当前仅限会员下单,请先登录'], 403);
```
→
```php
            return response()->json(['message' => __('messages.guest_only')], 403);
```
```php
                return response()->json(['message' => '验证码错误'], 422);
```
→
```php
                return response()->json(['message' => __('messages.captcha_error')], 422);
```
`query()` 内 `'未找到相关订单'` → `__('messages.order_not_found')`。
`OrderService` 内 `InsufficientStockException` 的 message → 用 `__('messages.insufficient_stock', ['need' => $qty, 'have' => $cards->count()])`。

- [ ] **Step 5: 验证多语言响应**

```bash
curl -s -H 'Accept-Language: en' -H 'X-Currency: USD' 'http://localhost:8000/api/orders/query?keyword=zzz' | head
# 期望 message 为英文 "No matching orders found."(订单不存在分支)
```

- [ ] **Step 6: Commit**

```bash
git add app/Http/Middleware/SetLocale.php lang/ bootstrap/app.php routes/api.php app/Http/Controllers/Api/OrderController.php app/Support/OrderService.php
git commit -m "feat: backend API i18n (SetLocale + lang files + first __() pass)"
```

---

## Task 8: 后台货币管理 CRUD（sysadmin 配置）

**Files:**
- Create: `app/Http/Controllers/Api/Admin/CurrencyController.php`
- Modify: `routes/api.php`（admin 货币路由）
- Create: `sysadmin/src/api/currency.ts`
- Create: `sysadmin/src/views/currency/list/index.vue`
- Modify: `sysadmin/src/router/modules/index.ts`（加菜单/路由）
- Modify: `sysadmin/src/locales/langs/zh.json` + `en.json`（货币管理文案）

- [ ] **Step 1: 写后台 CurrencyController**

`app/Http/Controllers/Api/Admin/CurrencyController.php`:
```php
<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Currency;
use App\Support\CurrencyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CurrencyController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(Currency::orderBy('sort')->get());
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'code' => 'required|string|size:3|unique:currencies,code',
            'name' => 'required|string|max:40',
            'symbol' => 'required|string|max:10',
            'symbol_position' => ['required', Rule::in(['before', 'after'])],
            'decimal_places' => 'required|integer|min:0|max:4',
            'exchange_rate' => 'required|numeric|min:0',
            'is_base' => 'boolean',
            'is_enabled' => 'boolean',
            'sort' => 'integer',
        ]);
        $data['code'] = strtoupper($data['code']);
        $cur = Currency::create($data);
        app(CurrencyService::class)->flushCache();
        return response()->json($cur, 201);
    }

    public function update(Request $request, string $code): JsonResponse
    {
        $cur = Currency::findOrFail(strtoupper($code));
        $data = $request->validate([
            'name' => 'sometimes|string|max:40',
            'symbol' => 'sometimes|string|max:10',
            'symbol_position' => ['sometimes', Rule::in(['before', 'after'])],
            'decimal_places' => 'sometimes|integer|min:0|max:4',
            'exchange_rate' => 'sometimes|numeric|min:0',
            'is_base' => 'sometimes|boolean',
            'is_enabled' => 'sometimes|boolean',
            'sort' => 'sometimes|integer',
        ]);
        // 设基础货币时,其余取消基础
        if (! empty($data['is_base'])) {
            Currency::where('is_base', true)->where('code', '!=', $cur->code)->update(['is_base' => false]);
            $data['exchange_rate'] = 1;
        }
        $cur->update($data);
        app(CurrencyService::class)->flushCache();
        return response()->json($cur->fresh());
    }

    public function destroy(string $code): JsonResponse
    {
        $cur = Currency::findOrFail(strtoupper($code));
        abort_if($cur->is_base, 422, '基础货币不可删除');
        $cur->delete();
        app(CurrencyService::class)->flushCache();
        return response()->json(null, 204);
    }
}
```

- [ ] **Step 2: 注册 admin 货币路由**

在 `routes/api.php` 的 `Route::middleware('auth:sanctum')->prefix('admin')` 组内（参考现有 `Route::apiResource('products', ...)` 形式）加：
```php
        Route::apiResource('currencies', AdminCurrencyController::class)->except(['show']);
```
并在顶部 `use` 引入：
```php
use App\Http\Controllers\Api\Admin\CurrencyController as AdminCurrencyController;
```

- [ ] **Step 3: 写 sysadmin api 模块**

`sysadmin/src/api/currency.ts`:
```ts
import request from '@/utils/http'

export interface Currency {
  code: string
  name: string
  symbol: string
  symbol_position: 'before' | 'after'
  decimal_places: number
  exchange_rate: number
  is_base: boolean
  is_enabled: boolean
  sort: number
}

export const getCurrencies = () => request.get<Currency[]>({ url: '/admin/currencies' })
export const createCurrency = (data: Partial<Currency>) =>
  request.post<Currency>({ url: '/admin/currencies', data })
export const updateCurrency = (code: string, data: Partial<Currency>) =>
  request.put<Currency>({ url: `/admin/currencies/${code}`, data })
export const deleteCurrency = (code: string) =>
  request.delete({ url: `/admin/currencies/${code}` })
```

- [ ] **Step 4: 写货币管理页面**

`sysadmin/src/views/currency/list/index.vue`：参考 sysadmin 现有列表页（如 `views/category/list/index.vue`）的模式构建一个表格 + 新增/编辑弹窗。列：货币代码、名称、符号、位置、小数位、汇率、基础货币(开关/单选)、启用(开关)、排序、操作(编辑/删除)。基础货币行禁用删除。汇率编辑后保存触发后端 flushCache。

（具体模板代码较长，照搬 sysadmin 现有列表页结构：`<el-table>` + `<el-dialog>` 表单 + CRUD 调用上面的 api。验证点见 Step 6。）

- [ ] **Step 5: 加路由 + 菜单 + i18n 文案**

在 `sysadmin/src/router/modules/index.ts` 参考 category 路由加一条 currency 路由（path `/currency`，component 指向新页）。在 `sysadmin/src/locales/langs/zh.json` 与 `en.json` 加 `zcard.currency.*` 文案（管理页标题/列名/按钮）。

- [ ] **Step 6: 验证后台 CRUD**

启动 sysadmin，进入货币管理页：新增 USD（设汇率 0.14）、设基础货币为 CNY、删除一个非基础货币、切换启用。每步检查 `/api/admin/currencies` 返回正确，且 `/api/currencies`（前台）随之变化（flushCache 生效）。

- [ ] **Step 7: Commit**

```bash
git add app/Http/Controllers/Api/Admin/CurrencyController.php routes/api.php sysadmin/src/api/currency.ts sysadmin/src/views/currency/ sysadmin/src/router/modules/index.ts sysadmin/src/locales/langs/zh.json sysadmin/src/locales/langs/en.json
git commit -m "feat: admin currency management page"
```

---

## Task 9: storefront 前端 —— 统一 formatMoney + 货币/语言状态 + 切换器

**Files:**
- Create: `storefront/src/utils/money.ts`
- Create: `storefront/src/api/currency.ts`
- Create: `storefront/src/stores/preferences.ts`
- Modify: `storefront/src/components/AppHeader.vue`（加货币/语言切换下拉）
- Modify: `storefront/src/main.ts`（preferences store 初始化）
- Modify: `storefront/src/api/request.ts`（axios 拦截器带 X-Currency/X-Lang 头）

- [ ] **Step 1: 写 money.ts 工具**

`storefront/src/utils/money.ts`:
```ts
/** 货币元信息(从 /api/currencies 拉取后存 store) */
export interface CurrencyInfo {
  code: string
  name: string
  symbol: string
  symbol_position: 'before' | 'after'
  decimal_places: number
  is_base: boolean
}

/** 最小单位(分) → 带符号展示字符串,如 "¥12.50" */
export function formatMoney(minUnit: number, cur: CurrencyInfo | null | undefined): string {
  if (!cur) return (minUnit / 100).toFixed(2)
  const divisor = Math.pow(10, cur.decimal_places)
  const value = (minUnit / divisor).toFixed(cur.decimal_places)
  return cur.symbol_position === 'before' ? `${cur.symbol}${value}` : `${value}${cur.symbol}`
}

/** 基础货币分 → 显示货币分(仅即时预览,下单以服务端为准) */
export function convertFen(baseFen: number, rate: number, decimalPlaces = 2): number {
  const yuan = baseFen / 100
  const converted = yuan * rate
  const minUnit = Math.pow(10, decimalPlaces)
  return Math.round(converted * minUnit)
}
```

- [ ] **Step 2: 写货币 API**

`storefront/src/api/currency.ts`:
```ts
import request from './request'
import type { CurrencyInfo } from '@/utils/money'

export interface CurrencyListResponse {
  base_currency: string
  currencies: CurrencyInfo[]
}

export const getCurrencies = () =>
  request.get<unknown, CurrencyListResponse>('/currencies')
```

- [ ] **Step 3: 写 preferences store**

`storefront/src/stores/preferences.ts`:
```ts
import { defineStore } from 'pinia'
import { getCurrencies, type CurrencyListResponse } from '@/api/currency'
import type { CurrencyInfo } from '@/utils/money'

export const usePreferencesStore = defineStore('preferences', {
  state: () => ({
    baseCurrency: 'CNY',
    currencies: [] as CurrencyInfo[],
    currency: (localStorage.getItem('zcard_currency') || '') as string,
    language: (localStorage.getItem('zcard_language') || '') as string,
    loaded: false,
  }),
  getters: {
    currentCurrency(state): CurrencyInfo | undefined {
      return state.currencies.find((c) => c.code === state.currency) || state.currencies.find((c) => c.is_base)
    },
  },
  actions: {
    async load() {
      if (this.loaded) return
      const data: CurrencyListResponse = await getCurrencies()
      this.baseCurrency = data.base_currency
      this.currencies = data.currencies
      if (!this.currency) this.currency = data.base_currency
      this.loaded = true
    },
    setCurrency(code: string) {
      this.currency = code
      localStorage.setItem('zcard_currency', code)
    },
    setLanguage(lang: string) {
      this.language = lang
      localStorage.setItem('zcard_language', lang)
    },
  },
})
```

- [ ] **Step 4: axios 拦截器带货币/语言头**

读 `storefront/src/api/request.ts`，在现有 axios 实例的请求拦截器（`instance.interceptors.request.use`）内追加头注入。从 localStorage 直接读（避免 store 循环依赖）：
```ts
instance.interceptors.request.use((config) => {
  // ...existing code...
  const cur = localStorage.getItem('zcard_currency')
  if (cur) config.headers['X-Currency'] = cur
  const lang = localStorage.getItem('zcard_language')
  if (lang) config.headers['X-Lang'] = lang
  return config
})
```
（先 Read 现有 request.ts，把上述两行插入现有拦截器内，不要新建拦截器。）

- [ ] **Step 5: AppHeader 加切换器**

在 `storefront/src/components/AppHeader.vue` 的 `<nav>` 内（登录/注册按钮之前）加两个下拉（用原生 `<select>` 或现有 UI 模式），绑定 preferences store：
```vue
<select v-model="currencySel" class="...">
  <option v-for="c in prefs.currencies" :key="c.code" :value="c.code">{{ c.code }}</option>
</select>
```
script 部分加：
```ts
import { usePreferencesStore } from '@/stores/preferences'
const prefs = usePreferencesStore()
onMounted(() => { prefs.load() })
const currencySel = computed({
  get: () => prefs.currency,
  set: (v: string) => { prefs.setCurrency(v); location.reload() }, // 切货币刷新以重算价格
})
```
（语言切换器在 Task 11 接 i18n 后补完整；本 Task 先放货币切换器。）

- [ ] **Step 6: main.ts 初始化 preferences**

在 `storefront/src/main.ts` 的 settingsStore.load() 之后加：
```ts
import { usePreferencesStore } from './stores/preferences'
const prefsStore = usePreferencesStore(pinia)
prefsStore.load()
```

- [ ] **Step 7: 验证**

`npm run dev`（storefront），打开前台，header 出现货币下拉；切到 USD 后刷新，商品价格按汇率变化（接口返回的 price_display 生效）。打开 devtools 网络面板确认请求带 `X-Currency` 头。

- [ ] **Step 8: Commit**

```bash
cd storefront && git add src/utils/money.ts src/api/currency.ts src/stores/preferences.ts src/components/AppHeader.vue src/main.ts src/api/request.ts
git commit -m "feat(storefront): money util + currency switcher"
```

---

## Task 10: storefront 全站替换 ¥ + fen/100 为 formatMoney

**Files:** ~10 个 .vue 文件（逐个改）
- `storefront/src/components/ProductCard.vue:12,26-27,45`
- `storefront/src/views/Home.vue:23,82`
- `storefront/src/views/Product.vue:41,77-78,102`
- `storefront/src/views/Checkout.vue:70,165,173,247`
- `storefront/src/views/MyOrders.vue:24,78`
- `storefront/src/views/OrderQuery.vue:41,151-152`
- `storefront/src/views/PayResult.vue:79`
- Modify: `storefront/src/api/products.ts`、`orders.ts`（TS 接口加 price_base/price_display/display_currency 字段）

- [ ] **Step 1: 更新 TS 接口加货币字段**

`storefront/src/api/products.ts` 的 `Product` 接口，把 `price: number` 扩展：
```ts
  price: number // 兼容旧(=price_base)
  price_base: number
  price_display: number
  display_currency: string
  exchange_rate: number
```
`storefront/src/api/orders.ts` 的订单接口同样加 `amount_base`/`amount_display`/`display_currency`/`exchange_rate`。

- [ ] **Step 2: 每个文件替换格式化逻辑**

对每个文件：删除本地 `const fmt = (fen) => (fen/100).toFixed(2)` 之类，改为 import + 使用：
```ts
import { formatMoney } from '@/utils/money'
import { usePreferencesStore } from '@/stores/preferences'
const prefs = usePreferencesStore()
// 模板内: ¥{{ fmt(p.price) }} → {{ formatMoney(p.price_display, prefs.currentCurrency) }}
```
逐文件替换 `¥` + `fen/100` → `formatMoney(item.price_display, prefs.currentCurrency)`。注意订单类用 `amount_display`。

- [ ] **Step 3: 逐文件改完后跑 typecheck + 人工检查**

```bash
cd storefront && npm run build   # 或 npx vue-tsc --noEmit
```
Expected: 无类型错误。打开各页面人工确认价格显示正确（基础货币场景 ¥，切 USD 后 $）。

- [ ] **Step 4: Commit（可分文件多次提交，或一次提交全部）**

```bash
cd storefront && git add src/components/ProductCard.vue src/views/Home.vue src/views/Product.vue src/views/Checkout.vue src/views/MyOrders.vue src/views/OrderQuery.vue src/views/PayResult.vue src/api/products.ts src/api/orders.ts
git commit -m "refactor(storefront): replace hardcoded ¥ with formatMoney"
```

---

# 阶段二 · 支付通道货币换算

## Task 11: PaymentDriver 契约 + PaymentResult 改造

**Files:**
- Modify: `app/Payment/Contracts/PaymentDriver.php`
- Modify: `app/Payment/PaymentResult.php`
- Test: `tests/Unit/PaymentResultTest.php`

- [ ] **Step 1: 改 PaymentDriver 契约加 getSupportedCurrencies**

`app/Payment/Contracts/PaymentDriver.php`，在接口内追加方法：
```php
    /**
     * 此驱动支持的货币 code 列表(spec §5.1)。
     * 法币驱动如支付宝返回 ['CNY'];PayPal 返回其通道配置的目标货币。
     */
    public function getSupportedCurrencies(): array;
```

- [ ] **Step 2: 改 PaymentResult 增加 currency/amount_sent**

`app/Payment/PaymentResult.php` 构造函数加两个可选属性：
```php
    public function __construct(
        public string $type,
        public ?string $redirectUrl = null,
        public ?string $qrcodeContent = null,
        public ?string $formHtml = null,
        public ?string $currencySent = null,
        public ?int $amountSent = null,
    ) {}
```
`toArray()` 内追加：
```php
            'currency_sent' => $this->currencySent,
            'amount_sent' => $this->amountSent,
```

- [ ] **Step 3: 写测试**

`tests/Unit/PaymentResultTest.php`:
```php
<?php

namespace Tests\Unit;

use App\Payment\PaymentResult;
use Tests\TestCase;

class PaymentResultTest extends TestCase
{
    public function test_holds_currency_and_amount_sent(): void
    {
        $r = new PaymentResult(PaymentResult::TYPE_FORM, formHtml: '<x/>', currencySent: 'USD', amountSent: 175);
        $arr = $r->toArray();
        $this->assertSame('USD', $arr['currency_sent']);
        $this->assertSame(175, $arr['amount_sent']);
    }
}
```

- [ ] **Step 4: 跑测试**

```bash
php artisan test tests/Unit/PaymentResultTest.php
```
Expected: PASS（此时 8 个驱动还没实现 getSupportedCurrencies，会因接口实现缺失报错——这正是下一个 Task 要补的；本 Task 仅测 PaymentResult）。

- [ ] **Step 5: Commit**

```bash
git add app/Payment/Contracts/PaymentDriver.php app/Payment/PaymentResult.php tests/Unit/PaymentResultTest.php
git commit -m "feat: currency-aware PaymentDriver contract + PaymentResult"
```

---

## Task 12: 8 个驱动实现 getSupportedCurrencies + 默认货币

**Files:** `app/Payment/Drivers/*.php`（8 个）

每个驱动追加 `getSupportedCurrencies()` 实现，并在 `pay()` 内从 config 读 `target_currency`/`exchange_rate`（法币类默认 CNY；PayPal 默认 USD）。

- [ ] **Step 1: Alipay/WechatPay/CodePay/Epay（CNY-only 法币驱动）**

每个文件追加：
```php
    public function getSupportedCurrencies(): array
    {
        return ['CNY'];
    }
```
`pay()` 内金额换算保持 `bcdiv((string) $order->amount, '100', 2)`（已是 CNY 元），无需改动金额逻辑。这 4 个驱动把"假设 CNY"显式化。

- [ ] **Step 2: PaypalDriver（目标货币可配）**

`app/Payment/Drivers/PaypalDriver.php` 追加：
```php
    public function getSupportedCurrencies(): array
    {
        $cur = strtoupper($this->targetCurrency ?? 'USD');
        return [$cur];
    }
```
`pay()` 内把硬编码 `'currency' => 'USD'` 改为从 config 读：
```php
        $targetCur = strtoupper($config['target_currency'] ?? 'USD');
        $rate = (float) ($config['exchange_rate'] ?? 1);
        // amount 是基础货币分 → 元 × rate → 目标货币元 → 分(PayPal 接口用元,这里算好目标金额)
        $targetYuan = bcmul(bcdiv((string) $order->amount, '100', 8), (string) $rate, 2);
        // PayPal 请求 currency 用 targetCur,total 用 targetYuan
```
返回 `PaymentResult` 时填 `currencySent: $targetCur, amountSent: (int) bcmul($targetYuan, '100', 0)`。

- [ ] **Step 3: StripeDriver / EpuSdtDriver（已有 currency 配置，规范化）**

每个追加 `getSupportedCurrencies()`，从 config 读 `currency`/`target_currency` 返回。`pay()` 内金额按 `exchange_rate`（默认 1）换算。

- [ ] **Step 4: UsdtDriver（加密币特殊）**

`getSupportedCurrencies()` 返回 `['USDT']`。`pay()` 内目标货币=USDT，汇率=USDT 单价（用现有 `config['rate']`），换算逻辑保持现状。

- [ ] **Step 5: 验证全部驱动实现接口（无抽象方法错误）**

```bash
php artisan tinker --execute="
foreach (glob('app/Payment/Drivers/*.php') as \$f) {
    \$cls = 'App\\Payment\\Drivers\\'.basename(\$f,'.php');
    \$d = new \$cls;
    echo \$cls.' => '.implode(',', \$d->getSupportedCurrencies()).PHP_EOL;
}"
```
Expected: 8 个驱动各打印支持的货币，无 "abstract method" 致命错误。

- [ ] **Step 6: Commit**

```bash
git add app/Payment/Drivers/
git commit -m "feat: 8 payment drivers implement getSupportedCurrencies + target currency"
```

---

## Task 13: payments 快照迁移 + PaymentService 通道换算

**Files:**
- Create: `database/migrations/2026_07_31_130030_add_charge_currency_to_payments_table.php`
- Modify: `app/Models/Payment.php`（fillable/casts）
- Modify: `app/Support/PaymentService.php`（createPayment 换算 + 写快照 + 回调校验）

- [ ] **Step 1: 写迁移**

`database/migrations/2026_07_31_130030_add_charge_currency_to_payments_table.php`:
```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->char('charged_currency', 3)->nullable()->after('amount');
            $table->bigInteger('charged_amount')->nullable()->comment('实收·最小单位')->after('charged_currency');
            $table->decimal('channel_exchange_rate', 20, 8)->nullable()->after('charged_amount');
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropColumn(['charged_currency', 'charged_amount', 'channel_exchange_rate']);
        });
    }
};
```

- [ ] **Step 2: Payment 模型加 fillable/casts**

`app/Models/Payment.php` 的 `$fillable` 加 `'charged_currency', 'charged_amount', 'channel_exchange_rate'`；`$casts` 加 `'channel_exchange_rate' => 'decimal:8'`。

- [ ] **Step 3: 改 PaymentService::createPayment 做通道换算 + 写快照**

`app/Support/PaymentService.php` 的 `createPayment`，在 `$driver->pay(...)` 调用前后加换算与快照：
```php
        $driver = $this->resolveDriver($channel);
        $config = $channel->config ?? [];
        // 通道目标货币 + 汇率(spec §5.3)
        $targetCur = strtoupper($config['target_currency'] ?? $driver->getSupportedCurrencies()[0] ?? 'CNY');
        $rate = (float) ($config['exchange_rate'] ?? 1);
        $targetMin = (int) bcmul(
            bcmul(bcdiv((string) $order->amount, '100', 8), (string) $rate, 8),
            '100', 0
        );

        $result = $driver->pay($order, $config);

        Payment::create([
            'order_id' => $order->id,
            'channel' => $channel->code,
            'amount' => $order->amount,
            'status' => 'pending',
            'charged_currency' => $targetCur,
            'charged_amount' => $result->amountSent ?? $targetMin,
            'channel_exchange_rate' => $rate,
        ]);

        return $result->toArray();
```

- [ ] **Step 4: 改 handleCallback 金额校验用 charged 金额**

`handleCallback` 内现有金额校验：
```php
        $actualFen = (int) ($data['amount'] ?? -1);
        if ($actualFen !== (int) $order->amount) {
            return 'fail: amount mismatch';
        }
```
改为：驱动回调的 amount 是目标货币分，校验应对比该订单最近一笔 payment 的 `charged_amount`：
```php
        $payment = Payment::where('order_id', $order->id)->orderByDesc('id')->first();
        $expectFen = $payment ? (int) $payment->charged_amount : (int) $order->amount;
        $actualFen = (int) ($data['amount'] ?? -1);
        if ($actualFen !== $expectFen) {
            return 'fail: amount mismatch';
        }
```
（CNY-only 通道 charged_amount == amount，行为不变；跨币通道用 charged_amount 核对，更准确。）

- [ ] **Step 5: 跑迁移 + 回归测试**

```bash
php artisan migrate
php artisan test
```
Expected: 迁移成功；现有支付相关测试（如有 mockPay 流程）不破。

- [ ] **Step 6: Commit**

```bash
git add database/migrations/2026_07_31_130030_add_charge_currency_to_payments_table.php app/Models/Payment.php app/Support/PaymentService.php
git commit -m "feat: payment channel currency conversion + charge snapshot"
```

---

## Task 14: 通道 config 货币字段 + 后台通道编辑 UI + 前台通道筛选

**Files:**
- Modify: 8 个驱动的 `getConfigFields()`（追加 target_currency/exchange_rate/supported_currencies 字段说明）
- Modify: sysadmin 支付通道编辑页（加货币配置）
- Modify: storefront 结账页（按货币筛选可见通道）
- Modify: `app/Http/Controllers/Api/OrderController.php` 或新增支付方式列表端点（按显示货币过滤）

- [ ] **Step 1: 各驱动 getConfigFields 追加货币字段**

对每个驱动 `getConfigFields()` 追加（CNY-only 驱动用默认值，跨币驱动允许编辑）：
```php
            'target_currency' => [
                'label' => '收款货币',
                'type' => 'text',
                'required' => false,
                'default' => 'CNY', // PayPal 用 'USD' 等
            ],
            'exchange_rate' => [
                'label' => '汇率(基础货币→收款货币)',
                'type' => 'text',
                'required' => false,
                'default' => '1',
            ],
```

- [ ] **Step 2: sysadmin 通道编辑页加货币配置**

读 sysadmin 支付通道编辑页（`sysadmin/src/views/payment/...`），表单追加"收款货币"和"汇率"两个输入，写入 channel.config。参考现有 config 字段渲染模式（动态读 getConfigFields）。

- [ ] **Step 3: 支付方式列表按货币过滤**

新增/改造端点返回可用通道时，每个通道附 `supported_currencies`（从 driver `getSupportedCurrencies()` 读）。storefront 结账页（`Checkout.vue`）按客户当前货币/基础货币筛选：仅显示 `supported_currencies` 含相关货币的通道。

后端在返回通道列表处（找到现有返回通道的 controller/service，如 PaymentService::getEnabledChannels 的消费方）追加：
```php
        'supported_currencies' => $driver->getSupportedCurrencies(),
        'target_currency' => $config['target_currency'] ?? $driver->getSupportedCurrencies()[0] ?? null,
```

- [ ] **Step 4: 验证完整支付流（mock + 至少一个真实回调模拟）**

```bash
# 配一个 CNY 通道(支付宝)和一个 USD 通道(PayPal,汇率0.14)
# storefront 结账:基础货币 CNY 时两个通道都可见;切 USD 后仍可见(因 PayPal 支持 USD)
# 下单后检查 payments 行 charged_currency/charged_amount 正确
php artisan tinker --execute="
\$p = App\\Models\\Payment::latest()->first();
echo \$p->charged_currency.' '.\$p->charged_amount.PHP_EOL;"
```
Expected: CNY 通道 charged_currency=CNY charged_amount=订单分；PayPal 通道 charged_currency=USD charged_amount=换算后分。

- [ ] **Step 5: Commit**

```bash
git add app/Payment/Drivers/ sysadmin/src/views/payment/ storefront/src/views/Checkout.vue <payment list controller>
git commit -m "feat: channel currency config UI + frontend channel filtering"
```

---

# 阶段三 · 多语言

## Task 15: storefront vue-i18n 搭建 + zh/en 语言包骨架

**Files:**
- Modify: `storefront/package.json`（加 vue-i18n 依赖）
- Create: `storefront/src/locales/index.ts`
- Create: `storefront/src/locales/langs/zh.json`、`en.json`
- Modify: `storefront/src/main.ts`（注册 i18n）

- [ ] **Step 1: 安装 vue-i18n**

```bash
cd storefront && npm install vue-i18n@^11
```
Expected: package.json dependencies 多出 vue-i18n。

- [ ] **Step 2: 写 i18n 入口（对齐 sysadmin 模式）**

`storefront/src/locales/index.ts`:
```ts
import { createI18n } from 'vue-i18n'
import zh from './langs/zh.json'
import en from './langs/en.json'

const getInitialLocale = (): string => {
  const saved = localStorage.getItem('zcard_language')
  if (saved) return saved
  const nav = navigator.language?.toLowerCase() ?? ''
  return nav.startsWith('en') ? 'en' : 'zh'
}

const i18n = createI18n({
  legacy: false,
  globalInjection: true,
  locale: getInitialLocale(),
  fallbackLocale: 'zh',
  messages: { zh, en },
})

export default i18n
```

- [ ] **Step 3: 写 zh.json / en.json 骨架（先放 header + 通用按钮）**

`storefront/src/locales/langs/zh.json`:
```json
{
  "nav": { "home": "首页", "orders": "订单查询", "mine": "我的订单", "login": "登录", "register": "注册", "logout": "退出" },
  "common": { "buy": "购买", "soldOut": "已售罄", "stock": "库存", "sold": "已售" }
}
```
`storefront/src/locales/langs/en.json`:
```json
{
  "nav": { "home": "Home", "orders": "Track Order", "mine": "My Orders", "login": "Login", "register": "Register", "logout": "Logout" },
  "common": { "buy": "Buy", "soldOut": "Sold Out", "stock": "Stock", "sold": "Sold" }
}
```

- [ ] **Step 4: main.ts 注册 i18n**

`storefront/src/main.ts` 加：
```ts
import i18n from './locales'
// ...
app.use(i18n)
```

- [ ] **Step 5: 验证编译**

```bash
cd storefront && npm run build
```
Expected: 无错误。

- [ ] **Step 6: Commit**

```bash
cd storefront && git add package.json package-lock.json src/locales/ src/main.ts
git commit -m "feat(storefront): setup vue-i18n with zh/en"
```

---

## Task 16: storefront 语言切换器 + preferences store 接 i18n

**Files:**
- Modify: `storefront/src/stores/preferences.ts`（setLanguage 切 i18n.locale）
- Modify: `storefront/src/components/AppHeader.vue`（语言下拉接 i18n）
- Modify: `storefront/src/main.ts`（启动时按 preferences 设 locale + 按 enabled_languages 过滤）

- [ ] **Step 1: preferences store setLanguage 同步 i18n**

`storefront/src/stores/preferences.ts` 顶部 import：
```ts
import i18n from '@/locales'
```
`setLanguage` action 内追加：
```ts
      i18n.global.locale.value = lang
```
state 增加 `languages: [] as string[]`、`load()` 内从 settings 读 `enabled_languages`（preferences load 可调 settingsStore）。

- [ ] **Step 2: AppHeader 语言下拉**

`AppHeader.vue` 加语言 `<select>`（仅显示 enabled_languages）：
```ts
const langSel = computed({
  get: () => prefs.language,
  set: (v: string) => prefs.setLanguage(v),
})
```
货币下拉已在 Task 9，这里并排放语言下拉。

- [ ] **Step 3: 验证切换**

启动前台，header 语言下拉切 en → nav 文字变英文（Home/Track Order…），切回 zh 恢复。刷新后保持上次选择（localStorage）。

- [ ] **Step 4: Commit**

```bash
cd storefront && git add src/stores/preferences.ts src/components/AppHeader.vue
git commit -m "feat(storefront): language switcher wired to i18n"
```

---

## Task 17: storefront 全站硬编码中文抽取到 i18n key

**Files:** 全部含中文硬编码的 .vue（ProductCard/Home/Product/Checkout/MyOrders/OrderQuery/Login/Register/AppFooter 等）

- [ ] **Step 1: 扫描所有硬编码中文**

```bash
cd storefront && grep -rn '[一-龥]' src/ --include='*.vue' --include='*.ts' | grep -v node_modules
```
把命中条目归类到 zh.json/en.json 的 key 命名空间（product/checkout/order/auth/footer 等）。

- [ ] **Step 2: 逐文件抽取**

每个 .vue 文件：`import { useI18n } from 'vue-i18n'`，`const { t } = useI18n()`，模板/脚本里中文字面量替换为 `t('namespace.key')`。例：
```vue
<RouterLink to="/">{{ t('nav.home') }}</RouterLink>
```
注意：来自 StorefrontConfig 的文案（site_name/footer_about 等）**保持单语言**（spec §4.4 取舍），不抽取。

- [ ] **Step 3: 补全 en.json**

为新增的每个 key 补英文翻译。

- [ ] **Step 4: typecheck + 全页面人工验证两种语言**

```bash
cd storefront && npm run build
```
逐页（首页/商品详情/结账/订单查询/登录/注册/我的订单）切换中英，确认无遗漏中文、无 key 缺失（fallback 显示 key 本身即说明漏译）。

- [ ] **Step 5: Commit（可分文件多次）**

```bash
cd storefront && git add src/ src/locales/langs/
git commit -m "feat(storefront): extract all hardcoded strings to i18n"
```

---

## Task 18: 后端剩余硬编码中文 __() 第二批 + lang/en 完善

**Files:**
- Modify: `app/` 下所有含中文 message/异常的控制器与服务（auth/bill/withdrawal/coupon/payment 等）
- Modify: `lang/zh_CN/messages.php`、`lang/en/messages.php`

- [ ] **Step 1: 扫描后端硬编码中文**

```bash
grep -rn "'[^']*[一-龥][^']*'" app/Http/Controllers app/Support app/Exceptions | grep -E "message|Exception|throw|json"
```
归类为 messages.php 的 key（auth/category/card/order/payment/bill/withdrawal/coupon 命名空间）。

- [ ] **Step 2: 逐处替换为 __()**

例 `AuthController` 的 `'用户名或密码错误'` → `__('messages.auth.invalid_credentials')`。带占位符的用 `__('messages.x', ['name' => ...])`。

- [ ] **Step 3: 补全 lang/en/messages.php**

每个新 key 补英文。

- [ ] **Step 4: 验证英文响应**

```bash
# 用 Accept-Language: en 触发各类错误响应,确认返回英文
curl -s -H 'Accept-Language: en' -X POST http://localhost:8000/api/auth/login -d 'email=a@b.c&password=wrong' | head
```

- [ ] **Step 5: Commit**

```bash
git add app/ lang/
git commit -m "feat: backend i18n second pass (auth/bill/withdrawal/coupon/payment messages)"
```

---

## Task 19: StorefrontConfig 语言管理配置 + sysadmin 语言 tab

**Files:**
- Modify: `sysadmin/src/views/setting/index.vue`（加语言/货币配置 tab）
- Modify: `sysadmin/src/locales/langs/zh.json`、`en.json`
- Modify: `sysadmin/src/enums/appEnum.ts`（LanguageEnum 扩展，可选）

- [ ] **Step 1: setting 页加"多语言与货币" tab**

`sysadmin/src/views/setting/index.vue` 参考现有 tabs 结构（`<el-tabs>`）加一个 tab，表单含：`base_currency`（select，选项从 `/admin/currencies` 拉）、`default_display_currency`（select）、`enabled_languages`（多选 zh/en）、`default_language`（select）。保存调 `updateSettings`。

- [ ] **Step 2: i18n 文案**

zh.json/en.json 加 `zcard.setting.tabLocale` 等 tab/字段文案。

- [ ] **Step 3: 验证**

后台设置页填多语言与货币配置保存 → 前台 `/api/currencies` 与 `/api/settings/storefront` 反映新配置（enabled_languages、base_currency 生效）。

- [ ] **Step 4: Commit**

```bash
cd sysadmin && git add src/views/setting/index.vue src/locales/langs/zh.json src/locales/langs/en.json src/enums/appEnum.ts
git commit -m "feat(sysadmin): locale & currency settings tab"
```

---

## Task 20: 端到端验证 + 文档更新

**Files:**
- Create/Modify: `README.md` 或运维文档（多货币多语言使用说明）
- 人工 E2E 验证清单

- [ ] **Step 1: E2E 验证清单（逐项过）**

1. 后台货币管理：增删货币、改汇率、设基础货币、启用/禁用。
2. 后台多语言配置：设 enabled_languages=[zh,en]、base_currency=CNY。
3. 前台默认（zh/CNY）：价格 ¥、文案中文。
4. 前台切 USD：价格 $ 按汇率、刷新保持。
5. 前台切 en：nav/按钮/提示英文；配置型文案（footer）保持原值（已知取舍）。
6. 下单：订单快照 base_currency/display_currency/exchange_rate/amount_display 写入正确。
7. 支付：CNY 通道 charged_currency=CNY；USD 通道（PayPal）charged_currency=USD charged_amount=换算值；回调金额校验通过。
8. API 错误响应随 Accept-Language 中英切换。
9. 历史订单（无快照列）展示回退基础货币不报错。

- [ ] **Step 2: 文档**

在 README 或新建 `docs/multi-currency-language.md` 说明：如何加货币、如何加语言、汇率维护、配置型文案 v1 限制。

- [ ] **Step 3: 全量测试回归**

```bash
php artisan test
cd storefront && npm run build
cd ../sysadmin && npm run build
```
Expected: 全绿。

- [ ] **Step 4: Commit**

```bash
git add README.md docs/multi-currency-language.md
git commit -m "docs: multi-currency & multi-language usage guide"
```

---

## Self-Review（计划完成后自查，已执行）

**1. Spec 覆盖**: §1 架构→Task3/4/13；§2 数据模型→Task1/4/13；§3 换算→Task3/5/6；§3.5 锁定→Task4；§4 多语言→Task7/15/16/17/18/19；§4.4 配置文案限制→Task17 注明保留；§5 支付→Task11/12/13/14；§6 前端→Task9/10；§7 后台→Task8/19；§8 三阶段→本计划三部分对齐。全覆盖。

**2. 占位符扫描**: 无 TBD/TODO；Task8/14/17/18 含"参考现有页/扫描"指令但都给了具体方法与代码范式，非占位。

**3. 类型一致**: `CurrencyService::convert` 返回 `['amount','rate','currency']` 在 Task3/4/6 一致；`PaymentResult` 新增 `currencySent/amountSent` 在 Task11/12/13 一致；前端 `formatMoney(minUnit, CurrencyInfo)` 在 Task9/10 一致；`preferences store` 字段在 Task9/16 一致。

**4. 风险点**: Task5/7 需先确认项目是 `bootstrap/app.php` 还是 `Kernel.php` 注册中间件——Task5 Step2 已注明先读再选。Task6 保留旧 `price`/`amount` 字段以兼容现有前端/测试，避免破坏。
