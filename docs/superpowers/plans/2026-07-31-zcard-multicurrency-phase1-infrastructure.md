# 多货币阶段一 · 货币基础设施 实施计划

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** 让 storefront 客户能切换显示货币浏览价格、下单时锁定汇率快照——但不动支付换算（留到阶段二）。

**Architecture:** 新增 `currencies` 表（货币字典 + 汇率）+ `CurrencyService` 换算/格式化服务。所有金额始终以基础货币（CNY·分）存储，显示货币纯展示层换算，下单时把汇率+显示金额快照写入 orders。前端引入统一 `formatMoney` 工具替换散落的 `¥`+`/100`，Header 加货币切换器。

**Tech Stack:** Laravel 13（Eloquent 迁移/模型/服务）、bcmath（货币运算）、Pinia（前端状态）、Vue 3 + TypeScript（storefront）。

**Scope note:** 本计划仅覆盖 spec 的**阶段一**。阶段二（支付通道货币重构）、阶段三（多语言）各自独立成 plan，待阶段一上线后再编写。

---

## 文件结构

### 新建（后端）
- `database/migrations/2026_07_31_000010_create_currencies_table.php` — 货币字典表
- `database/migrations/2026_07_31_000020_add_currency_snapshot_to_orders_table.php` — orders 快照 4 列
- `database/seeders/CurrencySeeder.php` — 货币种子数据
- `app/Models/Currency.php` — Currency 模型
- `app/Support/CurrencyService.php` — 换算/格式化/汇率服务（核心）
- `app/Http/Middleware/ResolveDisplayCurrency.php` — 解析请求显示货币
- `app/Http/Controllers/Api/Storefront/CurrencyController.php` — 公开货币列表 API

### 新建（前端 storefront）
- `storefront/src/utils/money.ts` — 统一货币格式化工具
- `storefront/src/components/CurrencySwitcher.vue` — 货币切换下拉
- `storefront/src/stores/currency.ts` — 当前货币状态（Pinia）

### 修改（后端）
- `app/Support/StorefrontConfig.php` — defaults 新增 4 个 key
- `app/Support/OrderService.php` — 下单时写入货币快照
- `app/Models/Order.php` — 新增快照列 fillable/cast
- `app/Http/Controllers/Api/ProductController.php` — 商品响应增加 display 字段
- `app/Http/Controllers/Api/OrderController.php` — 订单响应增加 display 字段
- `app/Support/OrderService.php`（getOrderDetail/myOrders/searchOrders）— 响应增加 display 字段
- `routes/api.php` — 注册货币路由 + 中间件

### 修改（前端 storefront）
- `storefront/src/api/settings.ts` — 类型加 currency 配置
- `storefront/src/api/products.ts` + `orders.ts` — 类型加 display 字段
- `storefront/src/components/AppHeader.vue` — 嵌入货币切换器
- `storefront/src/components/ProductCard.vue` — 用 formatMoney
- `storefront/src/views/Home.vue` / `Product.vue` / `Checkout.vue` / `MyOrders.vue` / `OrderQuery.vue` / `PayResult.vue` — 用 formatMoney

### 测试
- `tests/Unit/CurrencyServiceTest.php` — 换算/格式化单元测试
- `tests/Feature/CurrencyDisplayTest.php` — API display 字段 + 下单快照特性测试

---

## Task 1: 创建 currencies 表迁移

**Files:**
- Create: `database/migrations/2026_07_31_000010_create_currencies_table.php`

- [ ] **Step 1: 写迁移文件**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('currencies', function (Blueprint $table) {
            $table->char('code', 3)->primary()->comment('ISO 4217, 如 CNY/USD/EUR');
            $table->string('name')->comment('显示名,如 人民币');
            $table->string('symbol', 10)->comment('符号,如 ¥/$/€');
            $table->enum('symbol_position', ['before', 'after'])->default('before');
            $table->unsignedTinyInteger('decimal_places')->default(2)->comment('小数位 CNY/USD=2 JPY=0');
            $table->decimal('exchange_rate', 20, 8)->default(1)->comment('相对基础货币汇率: 显示金额=基础金额×rate');
            $table->boolean('is_base')->default(false)->comment('基础货币 全局唯一 rate 恒为1');
            $table->boolean('is_enabled')->default(false)->comment('是否前台可见');
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

- [ ] **Step 2: 运行迁移确认无误**

Run: `php artisan migrate`
Expected: 迁移成功，无报错

- [ ] **Step 3: 回滚测试**

Run: `php artisan migrate:rollback`
Expected: 回滚成功，currencies 表消失

- [ ] **Step 4: 重新迁移**

Run: `php artisan migrate`
Expected: 迁移成功

- [ ] **Step 5: Commit**

```bash
git add database/migrations/2026_07_31_000010_create_currencies_table.php
git commit -m "feat(multi-currency): add currencies table migration"
```

---

## Task 2: orders 表货币快照列迁移

**Files:**
- Create: `database/migrations/2026_07_31_000020_add_currency_snapshot_to_orders_table.php`

- [ ] **Step 1: 写迁移文件**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            // 下单瞬间的货币快照,仅记录不参与结算;历史订单为 null 时回退基础货币显示
            $table->char('base_currency', 3)->nullable()->after('amount')->comment('下单时基础货币 code');
            $table->char('display_currency', 3)->nullable()->after('base_currency')->comment('客户选择的显示货币 code');
            $table->decimal('exchange_rate', 20, 8)->nullable()->after('display_currency')->comment('下单时锁定的显示汇率');
            $table->bigInteger('amount_display')->nullable()->after('exchange_rate')->comment('显示货币·分(最小单位)');
        });
    }

    public function up(): void {} // placeholder removed below
};
```

注意：上面有重复 `up()`，正确版本如下（只用一个 up）：

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->char('base_currency', 3)->nullable()->after('amount')->comment('下单时基础货币 code');
            $table->char('display_currency', 3)->nullable()->after('base_currency')->comment('客户选择的显示货币 code');
            $table->decimal('exchange_rate', 20, 8)->nullable()->after('display_currency')->comment('下单时锁定的显示汇率');
            $table->bigInteger('amount_display')->nullable()->after('exchange_rate')->comment('显示货币·分(最小单位)');
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

（请使用这第二个完整版本，丢弃第一个。）

- [ ] **Step 2: 运行迁移**

Run: `php artisan migrate`
Expected: orders 表新增 4 列

- [ ] **Step 3: 验证列存在**

Run: `php artisan tinker --execute="echo implode(',', \Schema::getColumnListing('orders'));" | tr ',' '\n' | grep -E "currency|exchange|amount_display"`
Expected: 输出 `base_currency`、`display_currency`、`exchange_rate`、`amount_display` 四行

- [ ] **Step 4: Commit**

```bash
git add database/migrations/2026_07_31_000020_add_currency_snapshot_to_orders_table.php
git commit -m "feat(multi-currency): add currency snapshot columns to orders"
```

---

## Task 3: Currency 模型

**Files:**
- Create: `app/Models/Currency.php`

- [ ] **Step 1: 写模型**

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * 货币字典 + 汇率(spec §2.1)。
 * code 为 ISO 4217;is_base 的行 exchange_rate 恒为 1。
 */
class Currency extends Model
{
    public $incrementing = false;
    protected $keyType = 'string';
    protected $primaryKey = 'code';

    protected $fillable = [
        'code', 'name', 'symbol', 'symbol_position',
        'decimal_places', 'exchange_rate', 'is_base', 'is_enabled', 'sort',
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

- [ ] **Step 2: Commit**

```bash
git add app/Models/Currency.php
git commit -m "feat(multi-currency): add Currency model"
```

---

## Task 4: CurrencySeeder 种子数据

**Files:**
- Create: `database/seeders/CurrencySeeder.php`
- Modify: `database/seeders/DatabaseSeeder.php`

- [ ] **Step 1: 写 Seeder**

```php
<?php

namespace Database\Seeders;

use App\Models\Currency;
use Illuminate\Database\Seeder;

class CurrencySeeder extends Seeder
{
    public function run(): void
    {
        $currencies = [
            [
                'code' => 'CNY', 'name' => '人民币', 'symbol' => '¥',
                'symbol_position' => 'before', 'decimal_places' => 2,
                'exchange_rate' => 1, 'is_base' => true, 'is_enabled' => true, 'sort' => 1,
            ],
            [
                'code' => 'USD', 'name' => '美元', 'symbol' => '$',
                'symbol_position' => 'before', 'decimal_places' => 2,
                'exchange_rate' => 0.14, 'is_base' => false, 'is_enabled' => false, 'sort' => 2,
            ],
            [
                'code' => 'EUR', 'name' => '欧元', 'symbol' => '€',
                'symbol_position' => 'after', 'decimal_places' => 2,
                'exchange_rate' => 0.13, 'is_base' => false, 'is_enabled' => false, 'sort' => 3,
            ],
        ];

        foreach ($currencies as $c) {
            Currency::updateOrCreate(['code' => $c['code']], $c);
        }
    }
}
```

- [ ] **Step 2: 注册到 DatabaseSeeder**

在 `database/seeders/DatabaseSeeder.php` 的 `run()` 方法内，找到现有 seeder 调用列表，添加：
```php
$this->call([
    // ... 现有 seeders
    \Database\Seeders\CurrencySeeder::class,
]);
```
（若 DatabaseSeeder 已有 `$this->call([...])` 块，把 `CurrencySeeder::class` 追加进数组即可；不要替换已有项。）

- [ ] **Step 3: 运行 seeder**

Run: `php artisan db:seed --class=CurrencySeeder`
Expected: 无报错

- [ ] **Step 4: 验证数据**

Run: `php artisan tinker --execute="echo App\Models\Currency::pluck('code')->implode(',');"`
Expected: `CNY,USD,EUR`

- [ ] **Step 5: Commit**

```bash
git add database/seeders/CurrencySeeder.php database/seeders/DatabaseSeeder.php
git commit -m "feat(multi-currency): add currency seeder with CNY/USD/EUR"
```

---

## Task 5: StorefrontConfig 新增货币配置 key

**Files:**
- Modify: `app/Support/StorefrontConfig.php`（`defaults()` 方法，约 line 14-105）

- [ ] **Step 1: 在 `defaults()` 返回数组顶部添加 4 个 key**

在 `app/Support/StorefrontConfig.php` 的 `defaults()` 方法返回数组中，找到 `'category_nav_style' => 'pills',` 这一行**之前**，插入：

```php
            // 多货币设置(spec §2.3)
            'base_currency' => 'CNY',
            'default_display_currency' => 'CNY',

            // 多语言设置(spec §2.3, 阶段三启用,此处先占位)
            'enabled_languages' => ['zh'],
            'default_language' => 'zh',
```

- [ ] **Step 2: 验证配置可读**

Run: `php artisan tinker --execute="var_dump(App\Support\StorefrontConfig::get('base_currency'));"`
Expected: `string(3) "CNY"`

- [ ] **Step 3: Commit**

```bash
git add app/Support/StorefrontConfig.php
git commit -m "feat(multi-currency): add base_currency/default_display_currency config keys"
```

---

## Task 6: CurrencyService 换算/格式化服务（TDD）

这是核心服务。先写失败测试，再实现。

**Files:**
- Create: `tests/Unit/CurrencyServiceTest.php`
- Create: `app/Support/CurrencyService.php`

- [ ] **Step 1: 写失败测试**

```php
<?php

namespace Tests\Unit;

use App\Models\Currency;
use App\Support\CurrencyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CurrencyServiceTest extends TestCase
{
    use RefreshDatabase;

    private CurrencyService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(CurrencyService::class);
    }

    public function test_get_base_currency_returns_cny(): void
    {
        $this->seed(\Database\Seeders\CurrencySeeder::class);
        $this->assertSame('CNY', $this->service->getBaseCurrency());
    }

    public function test_convert_base_to_base_returns_same_amount(): void
    {
        $this->seed(\Database\Seeders\CurrencySeeder::class);
        $result = $this->service->convert(12500, 'CNY');
        $this->assertSame(12500, $result['amount']);
        $this->assertSame('1', $result['rate']); // rate 来自 decimal cast 为字符串
    }

    public function test_convert_cny_to_usd_applies_rate(): void
    {
        $this->seed(\Database\Seeders\CurrencySeeder::class);
        // 12500 分 CNY × 0.14 = 1750 分 USD
        $result = $this->service->convert(12500, 'USD');
        $this->assertSame(1750, $result['amount']);
        $this->assertSame('USD', $result['currency']);
    }

    public function test_format_cny_symbol_before(): void
    {
        $this->seed(\Database\Seeders\CurrencySeeder::class);
        // CNY: before, 2 decimals, symbol ¥
        $this->assertSame('¥125.00', $this->service->format(12500, 'CNY'));
    }

    public function test_format_usd_symbol_before(): void
    {
        $this->seed(\Database\Seeders\CurrencySeeder::class);
        $this->assertSame('$17.50', $this->service->format(1750, 'USD'));
    }

    public function test_format_eur_symbol_after(): void
    {
        $this->seed(\Database\Seeders\CurrencySeeder::class);
        // EUR: after, 1750分 → 17.50€
        $this->assertSame('17.50€', $this->service->format(1750, 'EUR'));
    }

    public function test_format_rounds_to_decimal_places(): void
    {
        $this->seed(\Database\Seeders\CurrencySeeder::class);
        // 12555 分 → 125.55 ¥
        $this->assertSame('¥125.55', $this->service->format(12555, 'CNY'));
    }

    public function test_get_enabled_currencies_only_returns_enabled(): void
    {
        $this->seed(\Database\Seeders\CurrencySeeder::class);
        $enabled = $this->service->getEnabledCurrencies();
        // 默认只有 CNY enabled
        $this->assertCount(1, $enabled);
        $this->assertSame('CNY', $enabled->first()->code);
    }
}
```

- [ ] **Step 2: 运行测试确认失败**

Run: `php artisan test tests/Unit/CurrencyServiceTest.php`
Expected: FAIL — `Class App\Support\CurrencyService does not exist`

- [ ] **Step 3: 实现 CurrencyService**

```php
<?php

namespace App\Support;

use App\Models\Currency;
use Illuminate\Support\Facades\Cache;

/**
 * 货币换算/格式化服务(spec §3.1)。
 * 基础金额(分) → 显示货币金额(分),按 currencies.exchange_rate 换算。
 * 汇率定义: 显示金额 = 基础金额 × exchange_rate。
 */
class CurrencyService
{
    private const CACHE_KEY = 'currencies.all';
    private const CACHE_TTL = 3600;

    /**
     * 基础金额(分) → 显示货币金额(分) + 用的汇率。
     * 返回 ['amount' => int, 'rate' => string, 'currency' => string]
     */
    public function convert(int $baseFen, string $toCurrency): array
    {
        $currency = $this->getCurrency($toCurrency);
        $rate = (string) $currency->exchange_rate;

        // bcmul: 基础分 × 汇率, 中间保留 8 位, 再按目标小数位四舍五入到「元」再转回分
        $decimals = $currency->decimal_places;
        $inYuan = bcdiv((string) $baseFen, '100', $decimals + 4); // 分→高精度元
        $convertedYuan = bcmul($inYuan, $rate, $decimals + 4);     // ×汇率
        // 四舍五入到目标小数位
        $roundedYuan = $this->bcRound($convertedYuan, $decimals);
        $amountFen = (int) bcmul($roundedYuan, '100', 0);           // 元→分

        return [
            'amount' => $amountFen,
            'rate' => $rate,
            'currency' => $toCurrency,
        ];
    }

    /**
     * 按货币的符号/位置/小数位格式化为展示字符串。
     */
    public function format(int $fen, string $currencyCode): string
    {
        $currency = $this->getCurrency($currencyCode);
        $decimals = $currency->decimal_places;
        $yuan = bcdiv((string) $fen, '100', $decimals); // 分→元, 直接取目标小数位(bcmath 截断, 配合下方 round)

        // bcdiv 不四舍五入, 需先用更多精度再 round
        $yuanPrecise = bcdiv((string) $fen, '100', $decimals + 4);
        $yuan = $this->bcRound($yuanPrecise, $decimals);

        $formatted = number_format((float) $yuan, $decimals, '.', '');
        return $currency->symbol_position === 'before'
            ? $currency->symbol . $formatted
            : $formatted . $currency->symbol;
    }

    public function getCurrency(string $code): Currency
    {
        return $this->all()->firstWhere('code', $code)
            ?? $this->all()->firstWhere('is_base', true)
            ?? Currency::where('code', 'CNY')->firstOrFail();
    }

    /**
     * 启用的货币(前台货币切换器用)。
     */
    public function getEnabledCurrencies()
    {
        return $this->all()->where('is_enabled', true)->sortBy('sort')->values();
    }

    public function getBaseCurrency(): string
    {
        return StorefrontConfig::get('base_currency') ?: 'CNY';
    }

    /**
     * 全部货币(带缓存)。管理员改汇率后调用 forget()。
     */
    public function all()
    {
        return Cache::remember(self::CACHE_KEY, self::CACHE_TTL, function () {
            return Currency::orderBy('sort')->get();
        });
    }

    public function forget(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    /**
     * bcmath 四舍五入。
     */
    private function bcRound(string $number, int $precision = 0): string
    {
        $neg = bccomp($number, '0', 20) < 0;
        if ($neg) {
            $number = bcmul($number, '-1', 20);
        }
        $factor = bcpow('10', (string) $precision);
        $scaled = bcmul($number, $factor, 4);
        $floored = bcmul(bcdiv($scaled, '1', 0), '1', 0); // 截断小数
        // 判断小数部分是否 >= 0.5
        $frac = bcsub($scaled, $floored, 4);
        if (bccomp($frac, '0.5', 4) >= 0) {
            $floored = bcadd($floored, '1', 0);
        }
        $rounded = bcdiv($floored, $factor, $precision);
        return $neg ? bcmul($rounded, '-1', $precision) : $rounded;
    }
}
```

- [ ] **Step 4: 运行测试确认通过**

Run: `php artisan test tests/Unit/CurrencyServiceTest.php`
Expected: 8 tests PASS

如果 `convert` 或 `format` 的舍入边界未通过，调整 `bcRound` 内部逻辑直到全部绿色。

- [ ] **Step 5: Commit**

```bash
git add app/Support/CurrencyService.php tests/Unit/CurrencyServiceTest.php
git commit -m "feat(multi-currency): add CurrencyService with convert/format + tests"
```

---

## Task 7: 绑定 CurrencyService 到容器（单例）

为了让 `app(CurrencyService::class)` 在请求内共享缓存，绑定为 scoped 单例。

**Files:**
- Modify: `app/Providers/AppServiceProvider.php`

- [ ] **Step 1: 在 register() 中绑定**

在 `app/Providers/AppServiceProvider.php` 的 `register()` 方法内添加：

```php
$this->app->scoped(\App\Support\CurrencyService::class);
```

- [ ] **Step 2: 验证绑定**

Run: `php artisan tinker --execute="echo get_class(app(App\Support\CurrencyService::class));"`
Expected: `App\Support\CurrencyService`

- [ ] **Step 3: Commit**

```bash
git add app/Providers/AppServiceProvider.php
git commit -m "feat(multi-currency): bind CurrencyService as scoped singleton"
```

---

## Task 8: ResolveDisplayCurrency 中间件

**Files:**
- Create: `app/Http/Middleware/ResolveDisplayCurrency.php`
- Modify: `bootstrap/app.php`（注册中间件别名）

- [ ] **Step 1: 写中间件**

```php
<?php

namespace App\Http\Middleware;

use App\Support\CurrencyService;
use Closure;
use Illuminate\Http\Request;

/**
 * 解析请求的显示货币(spec §3.2)。
 * 优先级: X-Currency 请求头 > ?currency= query > 兜底 default_display_currency。
 * 结果写入 request attribute 'currency',控制器/服务通过 CurrencyService 读取。
 */
class ResolveDisplayCurrency
{
    public function __construct(private CurrencyService $currencyService) {}

    public function handle(Request $request, Closure $next): mixed
    {
        $code = $request->header('X-Currency') ?: $request->query('currency');
        $default = \App\Support\StorefrontConfig::get('default_display_currency') ?: 'CNY';

        // 校验货币存在且启用; 否则回退默认
        $enabled = $this->currencyService->getEnabledCurrencies();
        $valid = $code && $enabled->contains('code', $code);

        $request->attributes->set('currency', $valid ? $code : $default);

        return $next($request);
    }
}
```

- [ ] **Step 2: 注册别名**

编辑 `bootstrap/app.php`，在 `->withMiddleware(function (Middleware $middleware) { ... })` 回调内添加别名：

```php
$middleware->alias([
    'currency' => \App\Http\Middleware\ResolveDisplayCurrency::class,
]);
```
（若已有 `$middleware->alias([...])`，把 `'currency' => ...` 合并进现有数组；不要覆盖已有别名。）

- [ ] **Step 3: Commit**

```bash
git add app/Http/Middleware/ResolveDisplayCurrency.php bootstrap/app.php
git commit -m "feat(multi-currency): add ResolveDisplayCurrency middleware"
```

---

## Task 9: 公开货币列表 API

**Files:**
- Create: `app/Http/Controllers/Api/Storefront/CurrencyController.php`
- Modify: `routes/api.php`

- [ ] **Step 1: 写 Controller**

```php
<?php

namespace App\Http\Controllers\Api\Storefront;

use App\Http\Controllers\Controller;
use App\Support\CurrencyService;
use Illuminate\Http\JsonResponse;

class CurrencyController extends Controller
{
    public function __construct(private CurrencyService $currencyService) {}

    /**
     * 启用的货币列表 + 当前基础货币/默认显示货币。
     * 前台货币切换器拉取此接口。
     */
    public function index(): JsonResponse
    {
        return response()->json([
            'currencies' => $this->currencyService->getEnabledCurrencies()->map(fn ($c) => [
                'code' => $c->code,
                'name' => $c->name,
                'symbol' => $c->symbol,
                'symbol_position' => $c->symbol_position,
                'decimal_places' => $c->decimal_places,
            ]),
            'base_currency' => $this->currencyService->getBaseCurrency(),
            'default_display_currency' => \App\Support\StorefrontConfig::get('default_display_currency') ?: 'CNY',
        ]);
    }
}
```

- [ ] **Step 2: 注册路由**

在 `routes/api.php` 中找到 storefront 公开路由组（含 `/settings/storefront` 那一段），添加：

```php
Route::get('/currencies', [\App\Http\Controllers\Api\Storefront\CurrencyController::class, 'index']);
```

- [ ] **Step 3: 验证接口**

Run: `php artisan tinker --execute="$this->seed(\Database\Seeders\CurrencySeeder::class);"` (若已 seed 可跳过)

Run: `php artisan serve`（如未运行）, 然后:
Run: `curl -s http://127.0.0.1:8000/api/currencies | php -r '$j=json_decode(stream_get_contents(STDIN),true); echo json_encode($j,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE);'`
Expected: 返回 JSON，含 currencies 数组（默认仅 CNY）、base_currency=CNY、default_display_currency=CNY

- [ ] **Step 4: Commit**

```bash
git add app/Http/Controllers/Api/Storefront/CurrencyController.php routes/api.php
git commit -m "feat(multi-currency): public currency list API"
```

---

## Task 10: 商品 API 响应增加 display 字段（特性测试 TDD）

让 storefront 商品接口返回 `price_base` / `price_display` / `display_currency` / `exchange_rate`。

**Files:**
- Modify: `app/Http/Controllers/Api/ProductController.php`（列表/详情，约 line 69/81/87）
- Test: `tests/Feature/CurrencyDisplayTest.php`

- [ ] **Step 1: 先看现有 ProductController 返回结构**

Run: `sed -n '55,100p' app/Http/Controllers/Api/ProductController.php`
确认现有返回 `price` 字段的位置（line 69/81/87 附近），后续把 `price` 改名为 `price_base` 并附加 display 字段。

- [ ] **Step 2: 写失败特性测试**

```php
<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CurrencyDisplayTest extends TestCase
{
    use RefreshDatabase;

    public function test_product_list_returns_display_fields_for_default_currency(): void
    {
        $this->seed(\Database\Seeders\CurrencySeeder::class);
        $category = Category::factory()->create();
        Product::factory()->create([
            'category_id' => $category->id,
            'price' => 12500, // 125 元
        ]);

        $response = $this->getJson('/api/products');

        $response->assertOk();
        $product = $response->json('data.0') ?? $response->json('data.products.0') ?? null;
        $this->assertNotNull($product, '商品数据未找到,请调整断言路径');
        $this->assertArrayHasKey('price_base', $product);
        $this->assertArrayHasKey('price_display', $product);
        $this->assertArrayHasKey('display_currency', $product);
        $this->assertSame(12500, $product['price_base']);
        $this->assertSame(12500, $product['price_display']); // CNY=CNY 相同
        $this->assertSame('CNY', $product['display_currency']);
    }

    public function test_product_list_converts_when_currency_header_is_usd(): void
    {
        $this->seed(\Database\Seeders\CurrencySeeder::class);
        // 启用 USD
        \App\Models\Currency::where('code', 'USD')->update(['is_enabled' => true]);

        $category = Category::factory()->create();
        Product::factory()->create([
            'category_id' => $category->id,
            'price' => 12500, // 125 元
        ]);

        $response = $this->withHeaders(['X-Currency' => 'USD'])->getJson('/api/products');

        $response->assertOk();
        $product = $response->json('data.0') ?? $response->json('data.products.0') ?? null;
        $this->assertSame('USD', $product['display_currency']);
        $this->assertSame(12500, $product['price_base']);
        // 125 元 × 0.14 = 17.50 USD = 1750 分
        $this->assertSame(1750, $product['price_display']);
    }
}
```

注意：`$response->json('data.0')` 的路径取决于现有列表分页结构。Step 1 的 sed 输出会揭示真实结构；若结构不同（如 `data.products`），调整断言路径使测试在改造前先红在正确位置。

- [ ] **Step 3: 应用 currency 中间件到 storefront 商品路由**

在 `routes/api.php` 中找到商品列表/详情路由，套上 `currency` 中间件。例如：
```php
Route::middleware('currency')->group(function () {
    Route::get('/products', [...]);
    Route::get('/products/{product}', [...]);
    // 以及订单相关 storefront 路由(见 Task 11)
});
```
（具体路由名按现有 routes/api.php 的 storefront 段为准。）

- [ ] **Step 4: 运行测试确认失败**

Run: `php artisan test tests/Feature/CurrencyDisplayTest.php`
Expected: FAIL — 商品返回里没有 `price_base`/`price_display`

- [ ] **Step 5: 改造 ProductController 返回**

在 `app/Http/Controllers/Api/ProductController.php` 中，把返回商品的每个地方，把 `'price' => $product->price` 改为使用一个辅助方法附加 display 字段。在 Controller 顶部注入或 app 解析 CurrencyService：

在类内加：
```php
public function __construct(private \App\Support\CurrencyService $currencyService) {}
```

加一个私有辅助方法（把单个 product 数组补上 display 字段）：
```php
private function withDisplayPrice(Product $product): array
{
    $currency = request()->attributes->get('currency') ?? 'CNY';
    $conv = $this->currencyService->convert($product->price, $currency);
    return [
        'price_base' => $product->price,
        'price_display' => $conv['amount'],
        'display_currency' => $conv['currency'],
        'exchange_rate' => $conv['rate'],
    ];
}
```

在列表/详情返回 product 时，合并此结果（保留现有其它字段）。例如详情返回：
```php
return response()->json(array_merge(
    $product->toArray(),
    $this->withDisplayPrice($product)
));
```
列表返回中，对每个 product `map` 合并 `withDisplayPrice`。若 `$product->toArray()` 已含 `price`，保留它作为 `price_base` 的同时可移除旧 `price` 键以避免歧义——本计划保留 `price`（= price_base 值）以兼容现有前端，但**新增** `price_base`/`price_display` 等键。

- [ ] **Step 6: 运行测试确认通过**

Run: `php artisan test tests/Feature/CurrencyDisplayTest.php`
Expected: 2 tests PASS

- [ ] **Step 7: Commit**

```bash
git add app/Http/Controllers/Api/ProductController.php routes/api.php tests/Feature/CurrencyDisplayTest.php
git commit -m "feat(multi-currency): product API returns display currency fields"
```

---

## Task 11: 订单 API 响应增加 display 字段

**Files:**
- Modify: `app/Support/OrderService.php`（`getOrderDetail`/`myOrders`/`searchOrders` 返回点，约 line 233/257/279）
- Modify: `app/Http/Controllers/Api/OrderController.php:55`（创建订单返回）

- [ ] **Step 1: 在 OrderService 加 display 辅助**

在 `app/Support/OrderService.php` 顶部注入 CurrencyService（构造函数或方法内 `app()`）。加私有方法：

```php
private function withDisplayAmount(Order $order): array
{
    $currency = request()?->attributes->get('currency') ?? 'CNY';
    $conv = app(\App\Support\CurrencyService::class)->convert($order->amount, $currency);
    return [
        'amount_base' => $order->amount,
        'amount_display' => $conv['amount'],
        'display_currency' => $conv['currency'],
        'exchange_rate' => $conv['rate'],
    ];
}
```

- [ ] **Step 2: 在订单返回点合并**

在 `getOrderDetail`（约 line 257）、`myOrders`（约 279）、`searchOrders`（约 233）的返回数组中，`array_merge($orderData, $this->withDisplayAmount($order))`。列表中 `myOrders` 对每条订单 map 合并。

注意：若订单已有下单时快照（`amount_display`/`display_currency` 非空），优先用快照值；为空才实时换算。在 `withDisplayAmount` 里加判断：

```php
private function withDisplayAmount(Order $order): array
{
    $currency = request()?->attributes->get('currency') ?? 'CNY';
    $service = app(\App\Support\CurrencyService::class);

    // 历史订单无快照,且当前显示货币与下单货币不同时实时换算;
    // 有快照则用快照
    if ($order->display_currency && $order->display_currency === $currency && $order->amount_display !== null) {
        return [
            'amount_base' => $order->amount,
            'amount_display' => $order->amount_display,
            'display_currency' => $order->display_currency,
            'exchange_rate' => $order->exchange_rate,
        ];
    }
    $conv = $service->convert($order->amount, $currency);
    return [
        'amount_base' => $order->amount,
        'amount_display' => $conv['amount'],
        'display_currency' => $conv['currency'],
        'exchange_rate' => $conv['rate'],
    ];
}
```

- [ ] **Step 3: 创建订单返回也加 display 字段**

`app/Http/Controllers/Api/OrderController.php:55` 当前返回 `'amount' => $order->amount`。改为合并 display 字段（创建时订单已有快照，见 Task 12）。改为：

```php
return response()->json([
    'order' => array_merge(
        $order->toArray(),
        app(\App\Support\CurrencyService::class)->convert($order->amount, request()->attributes->get('currency') ?? 'CNY')
            ? ['amount_base' => $order->amount] + (fn() => (
                $c = request()->attributes->get('currency') ?? 'CNY';
                $conv = app(\App\Support\CurrencyService::class)->convert($order->amount, $c);
                ['amount_display' => $conv['amount'], 'display_currency' => $conv['currency'], 'exchange_rate' => $conv['rate']]
            ))()
            : []
    ),
]);
```

上面这个写法过于绕，**改用清晰写法**：把整个 OrderController:55 的返回替换为：

```php
$currency = request()->attributes->get('currency') ?? 'CNY';
$conv = app(\App\Support\CurrencyService::class)->convert($order->amount, $currency);

return response()->json([
    'order' => array_merge($order->toArray(), [
        'amount_base' => $order->amount,
        'amount_display' => $conv['amount'],
        'display_currency' => $conv['currency'],
        'exchange_rate' => $conv['rate'],
    ]),
]);
```

- [ ] **Step 4: 把 currency 中间件覆盖订单 storefront 路由**

确认 `routes/api.php` 中订单查询/我的订单/订单详情路由在 Task 10 Step 3 的 `currency` 中间件组内（或单独套上 `->middleware('currency')`）。

- [ ] **Step 5: 写测试验证订单创建返回 display 字段**

在 `tests/Feature/CurrencyDisplayTest.php` 追加：

```php
public function test_create_order_returns_display_fields(): void
{
    $this->seed([\Database\Seeders\CurrencySeeder::class]);
    \App\Models\Currency::where('code', 'USD')->update(['is_enabled' => true]);

    $category = Category::factory()->create();
    $product = Product::factory()->create([
        'category_id' => $category->id,
        'price' => 12500,
    ]);
    // 补足库存(若系统需 Card 记录)
    \App\Models\Card::factory()->count(2)->create(['product_id' => $product->id, 'status' => 'unused']);

    $payload = [
        'product_id' => $product->id,
        'quantity' => 1,
        'contact' => 'test@example.com',
    ];

    $response = $this->withHeaders(['X-Currency' => 'USD'])->postJson('/api/orders', $payload);

    $response->assertCreated();
    $order = $response->json('order');
    $this->assertSame('USD', $order['display_currency']);
    $this->assertSame(12500, $order['amount_base']);
    $this->assertSame(1750, $order['amount_display']);
}
```

注意：下单 payload 与路由路径以现有 OrderController 为准（参考 p1c-order-checkout spec）。若 factory 名/字段名不同，按实际调整。`Card::factory()` 的 status 值若是常量，用 `\App\Models\Card::STATUS_UNUSED`。

- [ ] **Step 6: 运行测试**

Run: `php artisan test tests/Feature/CurrencyDisplayTest.php`
Expected: 3 tests PASS（含新增的 create_order）

若 create_order 测试因库存/factory 细节失败，先核对 Card factory 定义与 OrderController 的下单入参，调整测试 fixture 使其在改造前能走通下单逻辑（display 字段断言是新增点）。

- [ ] **Step 7: Commit**

```bash
git add app/Support/OrderService.php app/Http/Controllers/Api/OrderController.php routes/api.php tests/Feature/CurrencyDisplayTest.php
git commit -m "feat(multi-currency): order API returns display currency fields"
```

---

## Task 12: OrderService 下单写入货币快照

**Files:**
- Modify: `app/Support/OrderService.php`（`createOrder` 内 `Order::create([...])`，约 line 75-90）
- Modify: `app/Models/Order.php`（fillable/cast）

- [ ] **Step 1: Order 模型加 fillable + cast**

在 `app/Models/Order.php` 的 `$fillable` 数组加入：
```php
'base_currency', 'display_currency', 'exchange_rate', 'amount_display',
```
在 `$casts` 数组加入：
```php
'exchange_rate' => 'decimal:8',
'amount_display' => 'integer',
```

- [ ] **Step 2: createOrder 签名增加 currency 参数**

把 `createOrder` 方法签名从：
```php
public function createOrder(int $productId, ?int $skuId, int $qty, array $customer): Order
```
改为：
```php
public function createOrder(int $productId, ?int $skuId, int $qty, array $customer, ?string $displayCurrency = null): Order
```

- [ ] **Step 3: 在 Order::create 数组写入快照**

在 `createOrder` 的 `Order::create([...])` 调用前，计算快照（放在 `$amount` 已最终确定之后、`DB::transaction` 闭包内 `Order::create` 之前）：

```php
// 货币快照(spec §3.5)
$service = app(\App\Support\CurrencyService::class);
$baseCur = $service->getBaseCurrency();
$dCur = $displayCurrency ?: (\App\Support\StorefrontConfig::get('default_display_currency') ?: $baseCur);
$snapshot = $service->convert($amount, $dCur);
```

然后在 `Order::create([...])` 的数组里追加：
```php
'base_currency' => $baseCur,
'display_currency' => $dCur,
'exchange_rate' => $snapshot['rate'],
'amount_display' => $snapshot['amount'],
```

注意：`createOrder` 内 `$amount` 在 `DB::transaction` 闭包外计算（优惠券后），快照计算用闭包外变量即可。把这三行快照计算放在 `return DB::transaction(function () use (...) {` 的 `use` 列表里传入 `$snapshot`/`$baseCur`/`$dCur`，或直接在闭包内引用（PHP 闭包需 `use ($snapshot, $baseCur, $dCur, ...)`）。

- [ ] **Step 4: OrderController 下单调用传入 currency**

`app/Http/Controllers/Api/OrderController.php` 中调用 `createOrder` 的地方（store 方法），把请求解析的 currency 传入。在调用前加：

```php
$currency = request()->attributes->get('currency');
```

并把 `createOrder($productId, $skuId, $qty, $customer)` 改为 `createOrder($productId, $skuId, $qty, $customer, $currency)`。

- [ ] **Step 5: 更新 Task 11 的 create_order 测试断言快照**

在 `tests/Feature/CurrencyDisplayTest.php` 的 `test_create_order_returns_display_fields` 中追加对 DB 快照的断言：

```php
$this->assertDatabaseHas('orders', [
    'display_currency' => 'USD',
    'exchange_rate' => '0.14000000',
    'amount_display' => 1750,
    'base_currency' => 'CNY',
]);
```

- [ ] **Step 6: 运行测试**

Run: `php artisan test tests/Feature/CurrencyDisplayTest.php`
Expected: 3 tests PASS

- [ ] **Step 7: 运行全部测试确保无回归**

Run: `php artisan test`
Expected: 全绿（含 CurrencyServiceTest + CurrencyDisplayTest + 已有测试）

- [ ] **Step 8: Commit**

```bash
git add app/Models/Order.php app/Support/OrderService.php app/Http/Controllers/Api/OrderController.php tests/Feature/CurrencyDisplayTest.php
git commit -m "feat(multi-currency): snapshot display currency on order creation"
```

---

## Task 13: 前端 storefront — 类型与 money 工具

**Files:**
- Modify: `storefront/src/api/settings.ts`（类型加 currency 配置）
- Modify: `storefront/src/api/products.ts` + `orders.ts`（类型加 display 字段）
- Create: `storefront/src/utils/money.ts`

- [ ] **Step 1: money.ts 工具**

```ts
export interface CurrencyInfo {
  code: string
  name: string
  symbol: string
  symbol_position: 'before' | 'after'
  decimal_places: number
}

/**
 * 按货币的符号/位置/小数位格式化(分→展示串)。
 * 用字符串运算避免浮点误差。
 */
export function formatMoney(fen: number, currency: CurrencyInfo): string {
  const decimals = currency.decimal_places ?? 2
  // 分→元, 用整数运算四舍五入到目标小数位
  const factor = Math.pow(10, decimals)
  // fen 是分(2位), 目标可能是 0/2 位
  // 统一: 先把 fen 转成「最小单位整数」再格式化
  // 这里 fen 始终是「该货币的分(2位)」语义 → 直接 /100 后按 decimals 格式
  const yuan = fen / 100
  const rounded = Math.round(yuan * factor) / factor
  const formatted = rounded.toLocaleString('en-US', {
    minimumFractionDigits: decimals,
    maximumFractionDigits: decimals,
  })
  return currency.symbol_position === 'before'
    ? `${currency.symbol}${formatted}`
    : `${formatted}${currency.symbol}`
}
```

- [ ] **Step 2: products.ts / orders.ts 类型加 display 字段**

在 `storefront/src/api/products.ts` 的 Product 接口加：
```ts
price_base: number
price_display: number
display_currency: string
exchange_rate: string
```
（保留现有 `price` 字段以兼容。）

在 `storefront/src/api/orders.ts` 的 Order 接口加：
```ts
amount_base: number
amount_display: number
display_currency: string
exchange_rate: string
```

- [ ] **Step 3: settings.ts 类型加 currency 配置**

在 `storefront/src/api/settings.ts` 的 `StorefrontSettings` 接口加：
```ts
base_currency: string
default_display_currency: string
```

- [ ] **Step 4: Commit**

```bash
git add storefront/src/utils/money.ts storefront/src/api/products.ts storefront/src/api/orders.ts storefront/src/api/settings.ts
git commit -m "feat(multi-currency): storefront money util + type fields"
```

---

## Task 14: storefront currency store（Pinia）

**Files:**
- Create: `storefront/src/stores/currency.ts`
- Modify: `storefront/src/main.ts`（启动时拉货币）

- [ ] **Step 1: 写 store**

```ts
import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import type { CurrencyInfo } from '@/utils/money'
import { api } from '@/api'

const STORAGE_KEY = 'zcard.currency'

export const useCurrencyStore = defineStore('currency', () => {
  const currencies = ref<CurrencyInfo[]>([])
  const currentCode = ref<string>(localStorage.getItem(STORAGE_KEY) || '')
  const baseCurrency = ref<string>('CNY')
  const defaultDisplayCurrency = ref<string>('CNY')

  const current = computed<CurrencyInfo>(() => {
    return currencies.value.find((c) => c.code === currentCode.value)
      || currencies.value.find((c) => c.code === defaultDisplayCurrency.value)
      || currencies.value[0]
      || { code: 'CNY', name: '人民币', symbol: '¥', symbol_position: 'before', decimal_places: 2 }
  })

  async function load() {
    try {
      const res = await api.get('/currencies')
      currencies.value = res.data.currencies
      baseCurrency.value = res.data.base_currency
      defaultDisplayCurrency.value = res.data.default_display_currency
      if (!currentCode.value) {
        currentCode.value = defaultDisplayCurrency.value
      }
    } catch (e) {
      // 接口失败时回退 CNY
      currentCode.value = 'CNY'
    }
  }

  function setCurrency(code: string) {
    currentCode.value = code
    localStorage.setItem(STORAGE_KEY, code)
    // 通知后端后续请求用此货币(通过 axios 拦截器, 见 Step 3)
  }

  return { currencies, currentCode, current, baseCurrency, defaultDisplayCurrency, load, setCurrency }
})
```

注意：`api` 的导入路径与实例以现有 storefront axios 设置为准（参考 `storefront/src/api/` 下的现有封装，可能是 `import { http } from '@/api'` 或 `import api from '@/api'`）。实现时核对实际导出名并统一。

- [ ] **Step 2: 添加 axios 拦截器注入 X-Currency 头**

找到 storefront 的 axios 实例配置文件（`storefront/src/api/index.ts` 或类似）。在请求拦截器里加：

```ts
import { useCurrencyStore } from '@/stores/currency'

// 在现有的 request 拦截器内追加:
instance.interceptors.request.use((config) => {
  // ... 现有逻辑
  const currencyStore = useCurrencyStore()
  if (currencyStore.currentCode) {
    config.headers['X-Currency'] = currencyStore.currentCode
  }
  return config
})
```

注意：Pinia 在拦截器内使用需确保 store 已初始化（main.ts 中 createPinia 已注册、且 currencyStore.load() 已调用）。若拦截器先于 store 初始化执行，改为从 localStorage 直读：

```ts
config.headers['X-Currency'] = localStorage.getItem('zcard.currency') || ''
```

**推荐用 localStorage 直读方案**（避免 Pinia 初始化时序问题）。

- [ ] **Step 3: main.ts 启动时 load 货币**

在 `storefront/src/main.ts` 中，pinia 注册之后、mount 之前加：

```ts
import { useCurrencyStore } from '@/stores/currency'

// ... app.use(pinia) 之后
const currencyStore = useCurrencyStore()
await currencyStore.load()

// app.mount('#app')
```

若 main.ts 不便 async（依赖 mount 时机），改为 `.load()` 不 await（货币异步加载，首屏可能短暂显示基础货币，加载后响应式更新）。

- [ ] **Step 4: Commit**

```bash
git add storefront/src/stores/currency.ts storefront/src/main.ts storefront/src/api/index.ts
git commit -m "feat(multi-currency): storefront currency store + X-Currency header"
```

---

## Task 15: CurrencySwitcher 组件 + 嵌入 Header

**Files:**
- Create: `storefront/src/components/CurrencySwitcher.vue`
- Modify: `storefront/src/components/AppHeader.vue`

- [ ] **Step 1: 写组件**

```vue
<template>
  <div class="currency-switcher">
    <select v-model="selected" @change="onChange" class="currency-select">
      <option v-for="c in currencyStore.currencies" :key="c.code" :value="c.code">
        {{ c.symbol }} {{ c.code }}
      </option>
    </select>
  </div>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import { useCurrencyStore } from '@/stores/currency'

const currencyStore = useCurrencyStore()
const selected = computed({
  get: () => currencyStore.currentCode,
  set: (v: string) => currencyStore.setCurrency(v),
})

function onChange() {
  // 触发当前页价格刷新:发出事件或刷新路由数据
  // 最简单: reload 当前视图数据(由各页面 watch currentCode 自行处理)
  window.dispatchEvent(new CustomEvent('currency-changed'))
}
</script>

<style scoped>
.currency-select {
  padding: 4px 8px;
  border-radius: 6px;
  border: 1px solid var(--border-color, #ddd);
  background: var(--bg-color, #fff);
  font-size: 14px;
  cursor: pointer;
}
</style>
```

- [ ] **Step 2: 嵌入 AppHeader**

在 `storefront/src/components/AppHeader.vue` 的模板中（找到合适位置，如导航栏右侧、登录按钮旁），插入：

```vue
<CurrencySwitcher />
```

并在 script 中 import：
```ts
import CurrencySwitcher from './CurrencySwitcher.vue'
```

- [ ] **Step 3: 手动验证**

Run storefront dev（`cd storefront && npm run dev`），浏览器打开首页：
Expected: Header 出现货币下拉，默认显示 `¥ CNY`；下拉里应只有 CNY（因 USD/EUR 默认未启用）。

在后台启用 USD（数据库 `UPDATE currencies SET is_enabled=1 WHERE code='USD'`），刷新前端：
Expected: 下拉出现 USD 选项；切换到 USD 后页面价格符号变 `$`。

- [ ] **Step 4: Commit**

```bash
git add storefront/src/components/CurrencySwitcher.vue storefront/src/components/AppHeader.vue
git commit -m "feat(multi-currency): add currency switcher to header"
```

---

## Task 16: storefront 各页面用 formatMoney 替换 ¥ + /100

逐个文件替换散落的 `¥` 和 `(fen/100).toFixed(2)`。**每个文件改完单独验证一次再继续下一个。**

**Files:**
- Modify: `storefront/src/components/ProductCard.vue:12,26-27,45`
- Modify: `storefront/src/views/Home.vue:23,82`
- Modify: `storefront/src/views/Product.vue:41,77-78,102`
- Modify: `storefront/src/views/Checkout.vue:70,165,173,247`
- Modify: `storefront/src/views/MyOrders.vue:24,78`
- Modify: `storefront/src/views/OrderQuery.vue:41,151-152`
- Modify: `storefront/src/views/PayResult.vue:79`

- [ ] **Step 1: ProductCard.vue**

在 `<script setup>` import：
```ts
import { formatMoney } from '@/utils/money'
import { useCurrencyStore } from '@/stores/currency'
const currencyStore = useCurrencyStore()
```
把模板里 `¥{{ fmt(product.price) }}` 之类改为：
```vue
{{ formatMoney(product.price_display, currencyStore.current) }}
```
删除文件内局部的 `fmt` 函数（`const fmt = (fen) => (fen/100).toFixed(2)` 之类）。

- [ ] **Step 2: 验证 ProductCard**

Run dev，首页商品卡片价格应显示 `¥xx.xx`（CNY）或切换后 `$xx.xx`（USD）。

- [ ] **Step 3-7: 依次改 Home/Product/Checkout/MyOrders/OrderQuery/PayResult**

每个文件同样：import formatMoney + currencyStore，把价格展示处改用 `formatMoney(xxx_display, currencyStore.current)`，删除局部 `fmt`/`formatAmount`。注意：
- Checkout 提交按钮 `提交订单 ¥xxx` → `提交订单 {{ formatMoney(order.amount_display, currencyStore.current) }}`
- 下单时金额用接口返回的 `amount_display`/`display_currency`（已是服务端按选定货币换算+快照）。

- [ ] **Step 8: 全量手测 storefront**

Run dev，逐页检查价格显示：
- 首页（Home）/ 商品列表
- 商品详情（Product）
- 结算（Checkout）
- 我的订单（MyOrders）
- 订单查询（OrderQuery）
- 支付结果（PayResult）

切换 CNY ↔ USD，所有页面价格符号与小数位都应正确变化（USD 时用 `$`，金额按 0.14 汇率换算）。

- [ ] **Step 9: Commit**

```bash
git add storefront/src/components/ProductCard.vue storefront/src/views/Home.vue storefront/src/views/Product.vue storefront/src/views/Checkout.vue storefront/src/views/MyOrders.vue storefront/src/views/OrderQuery.vue storefront/src/views/PayResult.vue
git commit -m "feat(multi-currency): storefront pages use formatMoney with display currency"
```

---

## Task 17: sysadmin 货币管理页

让管理员 CRUD currencies 表。

**Files:**
- Create: `app/Http/Controllers/Api/Admin/CurrencyController.php`
- Modify: `routes/api.php`
- Create: `sysadmin/src/views/currency/list/index.vue`
- Modify: `sysadmin/src/router/modules/index.ts`
- Modify: `sysadmin/src/locales/langs/zh.json` + `en.json`

- [ ] **Step 1: 后端 CurrencyController（CRUD + 设基础货币 + 清缓存）**

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
    public function __construct(private CurrencyService $currencyService) {}

    public function index(): JsonResponse
    {
        return response()->json(Currency::orderBy('sort')->get());
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'code' => 'required|string|size:3|alpha|unique:currencies,code',
            'name' => 'required|string|max:50',
            'symbol' => 'required|string|max:10',
            'symbol_position' => ['required', Rule::in(['before', 'after'])],
            'decimal_places' => 'required|integer|min:0|max:4',
            'exchange_rate' => 'required|numeric|min:0',
            'is_base' => 'boolean',
            'is_enabled' => 'boolean',
            'sort' => 'integer',
        ]);
        $data['code'] = strtoupper($data['code']);

        $currency = \DB::transaction(function () use ($data) {
            // 设为基础货币时, 取消其它基础标记
            if (!empty($data['is_base'])) {
                Currency::where('is_base', true)->update(['is_base' => false, 'exchange_rate' => 1]);
                $data['exchange_rate'] = 1;
            }
            return Currency::create($data);
        });

        $this->currencyService->forget();
        return response()->json($currency, 201);
    }

    public function update(Request $request, Currency $currency): JsonResponse
    {
        $data = $request->validate([
            'name' => 'sometimes|string|max:50',
            'symbol' => 'sometimes|string|max:10',
            'symbol_position' => ['sometimes', Rule::in(['before', 'after'])],
            'decimal_places' => 'sometimes|integer|min:0|max:4',
            'exchange_rate' => 'sometimes|numeric|min:0',
            'is_base' => 'boolean',
            'is_enabled' => 'boolean',
            'sort' => 'sometimes|integer',
        ]);

        \DB::transaction(function () use (&$currency, $data) {
            if (!empty($data['is_base']) && !$currency->is_base) {
                Currency::where('is_base', true)->where('code', '!=', $currency->code)
                    ->update(['is_base' => false, 'exchange_rate' => 1]);
                $data['exchange_rate'] = 1;
            }
            // 基础货币汇率恒为1
            if ($currency->is_base) {
                $data['exchange_rate'] = 1;
            }
            $currency->update($data);
        });

        $this->currencyService->forget();
        return response()->json($currency->fresh());
    }

    public function destroy(Currency $currency): JsonResponse
    {
        if ($currency->is_base) {
            return response()->json(['message' => '不能删除基础货币'], 422);
        }
        $currency->delete();
        $this->currencyService->forget();
        return response()->json(null, 204);
    }
}
```

- [ ] **Step 2: 注册 admin 路由**

在 `routes/api.php` 的 admin 路由组（`/api/admin` 下，含 auth 中间件）加：
```php
Route::apiResource('currencies', \App\Http\Controllers\Api\Admin\CurrencyController::class);
```

- [ ] **Step 3: 验证 CRUD**

Run: `php artisan tinker --execute="$this->seed(\Database\Seeders\CurrencySeeder::class);"`
Run: 登录获取 admin token，然后测试（用 curl 或 tinker）：
- GET `/api/admin/currencies` → 返回列表
- PUT `/api/admin/currencies/USD` body `{"is_enabled":true}` → USD 启用
- 再 GET 公开 `/api/currencies` → 应包含 USD

- [ ] **Step 4: sysadmin 前端 API 封装**

参考 `sysadmin/src/api/categories.ts` 的模式，新建 `sysadmin/src/api/currencies.ts`：
```ts
import { http } from '@/api' // 以现有 sysadmin http 封装为准

export interface Currency {
  code: string
  name: string
  symbol: string
  symbol_position: 'before' | 'after'
  decimal_places: number
  exchange_rate: string
  is_base: boolean
  is_enabled: boolean
  sort: number
}

export const getCurrencyList = () => http.get<Currency[]>('/admin/currencies')
export const createCurrency = (data: Partial<Currency>) => http.post('/admin/currencies', data)
export const updateCurrency = (code: string, data: Partial<Currency>) => http.put(`/admin/currencies/${code}`, data)
export const deleteCurrency = (code: string) => http.delete(`/admin/currencies/${code}`)
```
（核对 sysadmin 现有 api 封装的导出名与 http 实例名，统一。）

- [ ] **Step 5: sysadmin 货币管理页面**

参考 `sysadmin/src/views/category/list/index.vue` 的列表+弹窗模式，新建 `sysadmin/src/views/currency/list/index.vue`：
- 表格列: code / name / symbol / 汇率 / 启用(switch) / 基础(标记) / 排序 / 操作(编辑/删除)
- 新增/编辑弹窗: code(新增时可填)/name/symbol/symbol_position(select)/decimal_places/exchange_rate/is_enabled/is_base(switch)
- 启用 switch、设基础货币即时调用 updateCurrency

- [ ] **Step 6: 注册路由菜单**

在 `sysadmin/src/router/modules/index.ts` 加路由（参考现有 category 模块）：
```ts
{
  path: '/currency',
  name: 'Currency',
  component: () => import('@/views/currency/list/index.vue'),
  meta: { title: '货币管理', icon: '💰' },
}
```
菜单标题用 i18n: 在 `sysadmin/src/locales/langs/zh.json` 和 `en.json` 加对应 key（如 `zcard.menu.currency`）。

- [ ] **Step 7: 手测 sysadmin**

Run sysadmin dev，登录后台：
- 货币管理页可见，列出 CNY/USD/EUR
- 编辑 USD 启用 → 保存成功
- 设基础货币逻辑：点 USD 设基础，CNY 自动取消基础
- 删除非基础货币成功；删除基础货币被拒

- [ ] **Step 8: Commit**

```bash
git add app/Http/Controllers/Api/Admin/CurrencyController.php routes/api.php sysadmin/src/api/currencies.ts sysadmin/src/views/currency/list/index.vue sysadmin/src/router/modules/index.ts sysadmin/src/locales/langs/zh.json sysadmin/src/locales/langs/en.json
git commit -m "feat(multi-currency): sysadmin currency management page"
```

---

## Task 18: 端到端验收 + 全量回归

**Files:** 无（验证任务）

- [ ] **Step 1: 全量后端测试**

Run: `php artisan test`
Expected: 全部 PASS（CurrencyServiceTest 8 个 + CurrencyDisplayTest 3 个 + 已有测试无回归）

- [ ] **Step 2: 数据库重置 + seed 全流程**

Run:
```bash
php artisan migrate:fresh --seed
```
Expected: 所有表重建，currencies 表有 CNY(base)/USD/EUR。

- [ ] **Step 3: storefront 端到端手测清单**

逐项验证：
1. 首次访问 → 默认 CNY 显示
2. 启用 USD（后台）→ storefront 刷新 → Header 下拉出现 USD
3. 切换 USD → 首页/详情价格变 `$` 且金额 = CNY×0.14
4. USD 下下单 → 订单显示 USD 金额，DB orders 表 display_currency=USD/exchange_rate=0.14/amount_display 正确
5. 切回 CNY 看同一订单 → 显示 CNY 金额（用快照 amount_display / 或实时换算）
6. 历史订单（快照为 null）→ 按 CNY 基础货币显示，不报错

- [ ] **Step 4: 边界检查**
- 货币表为空时前端不崩（回退 CNY）
- X-Currency 传非法值（如 XYZ）→ 中间件回退默认货币，不报错
- 管理员改汇率后 → 前端立即反映（缓存 forget 生效）

- [ ] **Step 5: 确认无遗留硬编码 ¥**

Run: `grep -rn "¥" storefront/src/ | grep -v "node_modules" | grep -v "money.ts"`
Expected: 仅剩 money.ts 或 locale 文件中的合理引用，view 文件不再有硬编码 ¥（特殊情况除外，如 footer 版权符号——这类保留）。

- [ ] **Step 6: 最终 Commit（如有遗漏修复）**

```bash
git add -A
git commit -m "test(multi-currency): e2e verification + fixes"
```

---

## 完成标志

阶段一完成当满足：
1. ✅ `currencies` 表 + Seeder 就位
2. ✅ `CurrencyService` 单测全绿（convert/format/启用列表）
3. ✅ storefront 商品/订单 API 返回 display 字段，特性测试全绿
4. ✅ 下单写入货币快照，历史订单优雅回退
5. ✅ storefront 货币切换器可用，全站价格随货币变化
6. ✅ sysadmin 货币管理页 CRUD + 设基础货币 + 启用/禁用
7. ✅ 全量 `php artisan test` 无回归
8. ✅ storefront 端到端 CNY↔USD 切换流畅

支付换算（阶段二）与多语言（阶段三）不在本计划范围。

---

## 后续阶段预告（独立 plan）

- **阶段二 · 支付换算重构**: PaymentDriver 契约加货币参数 + `getSupportedCurrencies()`；通道 config JSON 加 supported/target currency + exchange_rate；8 个驱动逐一适配；payments 表加 charged_currency/charged_amount/channel_exchange_rate；PaymentService 换算逻辑；前台通道可见性筛选。
- **阶段三 · 多语言**: storefront 引入 vue-i18n 抽取全部硬编码中文；后端 SetLocale 中间件 + `lang/zh_CN|en/messages.php`；控制器 `__()` 提取；sysadmin 语言管理 tab。
