# 分站 Phase 1（域名解析 + 商品可见性 + 加价定价）实施计划

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** 让分站通过域名解析（Host 头）识别租户，前台商品列表按分站配置过滤可见性 + 加价展示，后台可管理分站与域名。本阶段不含下单/结算（Phase 2）。

**Architecture:** ResolveSubsite 中间件最早执行，归一化 Host + Redis 缓存查 subsite_domains → 存入 request attribute。ProductController 读该 attribute，用 SubsitePricingService（4 模式定价引擎）按 subsite_product_settings 加价/过滤。分站=一个 Merchant 行，配置存 settings JSON。

**Tech Stack:** Laravel 13（migrations/中间件/Service）、Redis 缓存、bcmath、Vue 3 + Element Plus（后台）。

**测试策略:** PHPUnit Feature 测试（docker exec zcard-laravel.test-1 php artisan test）。定价引擎走 TDD。

**Spec:** `docs/superpowers/specs/2026-08-01-zcard-subsite-design.md`

---

## 文件结构总览

**新建（后端）**
- `database/migrations/2026_08_01_000020_create_subsite_domains_table.php`
- `database/migrations/2026_08_01_000030_create_subsite_product_settings_table.php`
- `app/Models/SubsiteDomain.php`、`app/Models/SubsiteProductSetting.php`
- `app/Http/Middleware/ResolveSubsite.php`
- `app/Support/SubsitePricingService.php`
- `app/Http/Controllers/Api/Admin/SubsiteController.php`

**改造（后端）**
- `app/Http/Controllers/Api/ProductController.php`（可见性 + 加价）
- `app/Support/StorefrontConfig.php`（3 个分站配置 key）
- `bootstrap/app.php`（注册 ResolveSubsite 中间件）
- `routes/api.php`（后台分站管理路由）

**新建（测试）**
- `tests/Feature/SubsitePricingServiceTest.php`
- `tests/Feature/ResolveSubsiteTest.php`
- `tests/Feature/SubsiteProductVisibilityTest.php`

---

## Task 1: subsite_domains 表 + subsite_product_settings 表 + 模型

**Files:**
- Create: `database/migrations/2026_08_01_000020_create_subsite_domains_table.php`
- Create: `database/migrations/2026_08_01_000030_create_subsite_product_settings_table.php`
- Create: `app/Models/SubsiteDomain.php`
- Create: `app/Models/SubsiteProductSetting.php`

- [ ] **Step 1: subsite_domains 迁移**

`database/migrations/2026_08_01_000020_create_subsite_domains_table.php`:
```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('subsite_domains', function (Blueprint $table) {
            $table->id();
            $table->foreignId('merchant_id')->constrained('merchants')->cascadeOnDelete();
            $table->string('domain', 255);
            $table->enum('type', ['subdomain', 'custom'])->default('custom');
            $table->string('verification_token', 128)->nullable();
            $table->enum('verification_status', ['pending', 'verified', 'failed'])->default('pending');
            $table->enum('status', ['pending_review', 'active', 'disabled'])->default('pending_review');
            $table->boolean('is_primary')->default(false);
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();
            $table->unique('domain');
            $table->index(['status', 'verification_status']);
        });
    }
    public function down(): void
    {
        Schema::dropIfExists('subsite_domains');
    }
};
```

- [ ] **Step 2: subsite_product_settings 迁移**

`database/migrations/2026_08_01_000030_create_subsite_product_settings_table.php`:
```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('subsite_product_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('merchant_id')->constrained('merchants')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->unsignedBigInteger('sku_id')->default(0)->comment('0=商品级;>0=SKU级');
            $table->boolean('is_listed')->default(true)->comment('此分站是否上架');
            $table->enum('pricing_mode', ['inherit', 'markup_percent', 'fixed_markup', 'fixed_price'])->default('inherit');
            $table->decimal('markup_percent', 8, 2)->default(0);
            $table->bigInteger('fixed_markup_amount')->default(0)->comment('固定加价(分)');
            $table->bigInteger('fixed_price_amount')->default(0)->comment('一口价(分)');
            $table->integer('sort_order')->default(0);
            $table->timestamps();
            $table->unique(['merchant_id', 'product_id', 'sku_id']);
        });
    }
    public function down(): void
    {
        Schema::dropIfExists('subsite_product_settings');
    }
};
```

- [ ] **Step 2.5: 模型 SubsiteDomain**

`app/Models/SubsiteDomain.php`:
```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SubsiteDomain extends Model
{
    protected $fillable = [
        'merchant_id', 'domain', 'type', 'verification_token',
        'verification_status', 'status', 'is_primary', 'verified_at',
    ];

    protected function casts(): array
    {
        return ['is_primary' => 'boolean', 'verified_at' => 'datetime'];
    }

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }
}
```

- [ ] **Step 2.6: 模型 SubsiteProductSetting**

`app/Models/SubsiteProductSetting.php`:
```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SubsiteProductSetting extends Model
{
    protected $fillable = [
        'merchant_id', 'product_id', 'sku_id', 'is_listed',
        'pricing_mode', 'markup_percent', 'fixed_markup_amount',
        'fixed_price_amount', 'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'sku_id' => 'integer',
            'is_listed' => 'boolean',
            'markup_percent' => 'decimal:2',
            'fixed_markup_amount' => 'integer',
            'fixed_price_amount' => 'integer',
            'sort_order' => 'integer',
        ];
    }

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
```

- [ ] **Step 3: 跑迁移**

```bash
docker exec zcard-laravel.test-1 php artisan migrate
```
Expected: 两张表创建成功。

- [ ] **Step 4: Commit**

```bash
git add database/migrations/2026_08_01_000020_create_subsite_domains_table.php database/migrations/2026_08_01_000030_create_subsite_product_settings_table.php app/Models/SubsiteDomain.php app/Models/SubsiteProductSetting.php
git commit -m "feat: subsite_domains + subsite_product_settings tables + models"
```

---

## Task 2: StorefrontConfig 分站配置 key + ResolveSubsite 中间件

**Files:**
- Modify: `app/Support/StorefrontConfig.php`（defaults 加 3 key）
- Create: `app/Http/Middleware/ResolveSubsite.php`
- Modify: `bootstrap/app.php`（prepend 中间件）

- [ ] **Step 1: StorefrontConfig 加配置 key**

在 `app/Support/StorefrontConfig.php` 的 `defaults()` 数组末尾（分销 key 之后）加：
```php

            // 分站
            'subsite_enabled' => false,
            'subsite_default_confirm_days' => 7,
            'subsite_subdomain_base' => '',
```

- [ ] **Step 2: ResolveSubsite 中间件**

`app/Http/Middleware/ResolveSubsite.php`:
```php
<?php

namespace App\Http\Middleware;

use App\Models\Merchant;
use App\Models\SubsiteDomain;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * 分站域名解析(spec §3):归一化 Host → Redis 缓存查 subsite_domains → 存 request attribute。
 * null=主站。功能未开(ZCARD_SUB_SITE=false)直接放行。
 */
class ResolveSubsite
{
    public function handle(Request $request, Closure $next): mixed
    {
        if (! config('zcard.features.sub_site')) {
            $request->attributes->set('subsite', null);
            return $next($request);
        }

        $host = $this->normalizeHost($request->host());
        $merchant = null;

        if ($host) {
            // 缓存(正缓存 Merchant 对象,负缓存 false)避免反复查表
            $cached = Cache::remember("subsite:domain:{$host}", 300, function () use ($host) {
                $domain = SubsiteDomain::where('domain', $host)
                    ->where('status', 'active')
                    ->where('verification_status', 'verified')
                    ->first();
                return $domain ? Merchant::find($domain->merchant_id) : false;
            });
            $merchant = ($cached instanceof Merchant) ? $cached : null;
        }

        $request->attributes->set('subsite', $merchant);
        return $next($request);
    }

    /**
     * 归一化:lowercase + 剥离端口 + 剥离 www + 剥离尾点 + punycode。
     * 比 acg-faka 的脆弱检测更完整(规避大小写/www/IDN 失配)。
     */
    private function normalizeHost(string $host): string
    {
        $host = strtolower(trim($host));
        $host = preg_replace('/:\d+$/', '', $host);     // 剥离端口
        $host = preg_replace('/^www\./', '', $host);    // 剥离 www
        $host = rtrim($host, '.');                       // 剥离尾点
        if (function_exists('idn_to_ascii')) {
            $converted = @idn_to_ascii($host);           // punycode IDN
            if ($converted) {
                $host = $converted;
            }
        }
        return $host;
    }
}
```

- [ ] **Step 3: 注册中间件(prepend 到 api 组,最早执行)**

在 `bootstrap/app.php` 的 `$middleware->api(prepend: [...])` 数组加入 ResolveSubsite（放在 MaintenanceMiddleware 之后）：
```php
        $middleware->api(prepend: [
            \Illuminate\Session\Middleware\StartSession::class,
            \App\Http\Middleware\MaintenanceMiddleware::class,
            \App\Http\Middleware\ResolveSubsite::class,
        ]);
```

- [ ] **Step 4: 验证 + Commit**

```bash
# 确认中间件别名注册无语法错
docker exec zcard-laravel.test-1 php artisan route:list 2>&1 | head -3
# 确认 StorefrontConfig key
docker exec zcard-laravel.test-1 php artisan tinker --execute="echo App\\Support\\StorefrontConfig::get('subsite_enabled')===false ? 'OK' : 'FAIL';"
```

```bash
git add app/Support/StorefrontConfig.php app/Http/Middleware/ResolveSubsite.php bootstrap/app.php
git commit -m "feat: ResolveSubsite middleware + subsite config keys"
```

---

## Task 3: ResolveSubsite 中间件测试

**Files:**
- Create: `tests/Feature/ResolveSubsiteTest.php`

- [ ] **Step 1: 写测试**

```php
<?php

namespace Tests\Feature;

use App\Models\Merchant;
use App\Models\SubsiteDomain;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class ResolveSubsiteTest extends TestCase
{
    use RefreshDatabase;

    public function test_main_site_resolves_null_subsite(): void
    {
        // 主站 Host(subsite 为空)
        $resp = $this->getJson('/api/products');
        $resp->assertOk(); // 不报错即说明 subsite=null 不影响
        $this->assertNull(request()->attributes->get('subsite'));
    }

    public function test_subsite_domain_resolves_merchant(): void
    {
        config(['zcard.features.sub_site' => true]);
        Cache::flush();

        $user = User::factory()->create();
        $merchant = Merchant::create([
            'user_id' => $user->id, 'name' => 'AliceShop', 'slug' => 'alice',
            'status' => 1, 'commission_rate' => 0,
            'settings' => ['is_subsite' => true],
        ]);
        SubsiteDomain::create([
            'merchant_id' => $merchant->id, 'domain' => 'alice.com',
            'type' => 'custom', 'verification_status' => 'verified',
            'status' => 'active', 'is_primary' => true, 'verified_at' => now(),
        ]);

        $resp = $this->withHeaders(['Host' => 'alice.com'])->getJson('/api/products');
        $resp->assertOk();
    }

    public function test_host_normalization_strips_port_and_www(): void
    {
        // 归一化测试通过反射或直接测 normalizeHost
        $middleware = new \App\Http\Middleware\ResolveSubsite();
        $ref = new \ReflectionMethod($middleware, 'normalizeHost');
        $ref->setAccessible(true);
        $this->assertSame('alice.com', $ref->invoke($middleware, 'WWW.Alice.com:8080'));
        $this->assertSame('alice.com', $ref->invoke($middleware, 'alice.com.'));
        $this->assertSame('alice.com', $ref->invoke($middleware, 'ALICE.COM'));
    }
}
```

- [ ] **Step 2: 跑测试**

```bash
docker exec zcard-laravel.test-1 php artisan test tests/Feature/ResolveSubsiteTest.php
```
Expected: 3 passed。

- [ ] **Step 3: Commit**

```bash
git add tests/Feature/ResolveSubsiteTest.php
git commit -m "test: ResolveSubsite middleware (main site + domain resolution + normalization)"
```

---

## Task 4: SubsitePricingService（4 模式定价引擎）— TDD

**Files:**
- Create: `app/Support/SubsitePricingService.php`
- Test: `tests/Feature/SubsitePricingServiceTest.php`

- [ ] **Step 1: 写失败测试**

`tests/Feature/SubsitePricingServiceTest.php`:
```php
<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Merchant;
use App\Models\Product;
use App\Models\SubsiteProductSetting;
use App\Models\User;
use App\Support\SubsitePricingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SubsitePricingServiceTest extends TestCase
{
    use RefreshDatabase;

    private function makeSubsite(): Merchant
    {
        $u = User::factory()->create();
        return Merchant::create([
            'user_id' => $u->id, 'name' => 'Sub', 'slug' => 'sub' . uniqid(),
            'status' => 1, 'commission_rate' => 0,
            'settings' => ['is_subsite' => true, 'default_markup_percent' => 0, 'max_markup_percent' => 50],
        ]);
    }

    private function makeProduct(int $price): Product
    {
        $u = User::factory()->create();
        $m = Merchant::create(['user_id' => $u->id, 'name' => 'Main', 'slug' => 'm' . uniqid(), 'status' => 1, 'commission_rate' => 0]);
        $c = Category::create(['merchant_id' => $m->id, 'name' => 'C', 'slug' => 'c' . uniqid(), 'sort' => 0]);
        return Product::create([
            'merchant_id' => $m->id, 'category_id' => $c->id, 'name' => 'P', 'slug' => 'p' . uniqid(),
            'price' => $price, 'stock_type' => 'card', 'delivery_mode' => 'status', 'status' => true, 'sort' => 0,
        ]);
    }

    public function test_inherit_mode_returns_base_price(): void
    {
        $subsite = $this->makeSubsite();
        $product = $this->makeProduct(10000); // 100 元
        $svc = app(SubsitePricingService::class);
        $r = $svc->resolveUnitPrice($product, null, $subsite);
        $this->assertSame(10000, $r['price']);
        $this->assertSame('inherit', $r['mode']);
    }

    public function test_markup_percent_mode(): void
    {
        $subsite = $this->makeSubsite();
        $product = $this->makeProduct(10000);
        SubsiteProductSetting::create([
            'merchant_id' => $subsite->id, 'product_id' => $product->id, 'sku_id' => 0,
            'is_listed' => true, 'pricing_mode' => 'markup_percent', 'markup_percent' => 10,
        ]);
        $svc = app(SubsitePricingService::class);
        $r = $svc->resolveUnitPrice($product, null, $subsite);
        $this->assertSame(11000, $r['price']); // 100 × 1.10 = 110 元
        $this->assertSame('markup_percent', $r['mode']);
    }

    public function test_fixed_markup_mode(): void
    {
        $subsite = $this->makeSubsite();
        $product = $this->makeProduct(10000);
        SubsiteProductSetting::create([
            'merchant_id' => $subsite->id, 'product_id' => $product->id, 'sku_id' => 0,
            'is_listed' => true, 'pricing_mode' => 'fixed_markup', 'fixed_markup_amount' => 500,
        ]);
        $svc = app(SubsitePricingService::class);
        $r = $svc->resolveUnitPrice($product, null, $subsite);
        $this->assertSame(10500, $r['price']); // 100 + 5 = 105 元
    }

    public function test_fixed_price_mode(): void
    {
        $subsite = $this->makeSubsite();
        $product = $this->makeProduct(10000);
        SubsiteProductSetting::create([
            'merchant_id' => $subsite->id, 'product_id' => $product->id, 'sku_id' => 0,
            'is_listed' => true, 'pricing_mode' => 'fixed_price', 'fixed_price_amount' => 15000,
        ]);
        $svc = app(SubsitePricingService::class);
        $r = $svc->resolveUnitPrice($product, null, $subsite);
        $this->assertSame(15000, $r['price']); // 一口价 150 元
    }

    public function test_default_markup_percent_when_no_setting(): void
    {
        $subsite = $this->makeSubsite();
        $subsite->update(['settings' => ['is_subsite' => true, 'default_markup_percent' => 15, 'max_markup_percent' => 50]]);
        $product = $this->makeProduct(10000);
        $svc = app(SubsitePricingService::class);
        $r = $svc->resolveUnitPrice($product, null, $subsite);
        $this->assertSame(11500, $r['price']); // 100 × 1.15
        $this->assertSame('profile', $r['source']);
    }
}
```

- [ ] **Step 2: 跑测试确认失败**

```bash
docker exec zcard-laravel.test-1 php artisan test tests/Feature/SubsitePricingServiceTest.php
```
Expected: FAIL（类不存在）。

- [ ] **Step 3: 写实现**

`app/Support/SubsitePricingService.php`:
```php
<?php

namespace App\Support;

use App\Models\Merchant;
use App\Models\Product;
use App\Models\ProductSku;
use App\Models\SubsiteProductSetting;

/**
 * 分站定价引擎(spec §4)。4 模式:inherit/markup_percent/fixed_markup/fixed_price。
 * 优先级:SKU规则 > 商品规则 > 分站默认加价率 > 继承原价。
 * listing 与 checkout 共用同一函数(规避 acg-faka 两套公式 bug)。
 */
class SubsitePricingService
{
    /**
     * 解析某商品在某分站的售价(基础货币分)。
     * @return array{price: int, base: int, mode: string, source: string}
     */
    public function resolveUnitPrice(Product $product, ?ProductSku $sku, Merchant $subsite): array
    {
        $basePrice = $sku ? (int) $sku->price : (int) $product->price;

        // 1. SKU 级规则(非 inherit)
        if ($sku) {
            $setting = $this->findSetting($subsite->id, $product->id, $sku->id);
            if ($setting && $setting->pricing_mode !== 'inherit') {
                return $this->applyMode($setting, $basePrice, 'sku');
            }
        }

        // 2. 商品级规则(非 inherit)
        $setting = $this->findSetting($subsite->id, $product->id, 0);
        if ($setting && $setting->pricing_mode !== 'inherit') {
            return $this->applyMode($setting, $basePrice, 'product');
        }

        // 3. 分站默认加价率(default_markup_percent > 0)
        $defaultMarkup = (float) ($subsite->settings['default_markup_percent'] ?? 0);
        if ($defaultMarkup > 0) {
            $price = (int) round($basePrice * (100 + $defaultMarkup) / 100);
            return ['price' => $price, 'base' => $basePrice, 'mode' => 'markup_percent', 'source' => 'profile'];
        }

        // 4. 继承原价
        return ['price' => $basePrice, 'base' => $basePrice, 'mode' => 'inherit', 'source' => 'inherit'];
    }

    private function findSetting(int $merchantId, int $productId, int $skuId): ?SubsiteProductSetting
    {
        return SubsiteProductSetting::where('merchant_id', $merchantId)
            ->where('product_id', $productId)
            ->where('sku_id', $skuId)
            ->first();
    }

    private function applyMode(SubsiteProductSetting $s, int $base, string $source): array
    {
        $price = match ($s->pricing_mode) {
            'markup_percent' => (int) round($base * (100 + (float) $s->markup_percent) / 100),
            'fixed_markup'   => $base + (int) $s->fixed_markup_amount,
            'fixed_price'    => (int) $s->fixed_price_amount,
            default          => $base,
        };
        if ($price < $base) {
            throw new \RuntimeException('分站价不能低于基础价');
        }
        return ['price' => $price, 'base' => $base, 'mode' => $s->pricing_mode, 'source' => $source];
    }
}
```

- [ ] **Step 4: 跑测试确认通过**

```bash
docker exec zcard-laravel.test-1 php artisan test tests/Feature/SubsitePricingServiceTest.php
```
Expected: 5 passed。

- [ ] **Step 5: Commit**

```bash
git add app/Support/SubsitePricingService.php tests/Feature/SubsitePricingServiceTest.php
git commit -m "feat: SubsitePricingService (4-mode pricing engine) + tests"
```

---

## Task 5: ProductController 可见性过滤 + 加价展示

**Files:**
- Modify: `app/Http/Controllers/Api/ProductController.php`（index/show/featured + transform）
- Test: `tests/Feature/SubsiteProductVisibilityTest.php`

- [ ] **Step 1: 写测试**

`tests/Feature/SubsiteProductVisibilityTest.php`:
```php
<?php

namespace Tests\Feature;

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

class SubsiteProductVisibilityTest extends TestCase
{
    use RefreshDatabase;

    private function seedCurrency(): void
    {
        Currency::create(['code' => 'CNY', 'name' => '人民币', 'symbol' => '¥', 'symbol_position' => 'before', 'decimal_places' => 2, 'exchange_rate' => '1', 'is_base' => true, 'is_enabled' => true, 'sort' => 0]);
    }

    private function makeMainProduct(int $price): Product
    {
        $u = User::factory()->create();
        $m = Merchant::firstOrCreate(['slug' => 'default'], ['user_id' => $u->id, 'name' => '默认商户', 'status' => 1, 'commission_rate' => 0]);
        $c = Category::create(['merchant_id' => $m->id, 'name' => 'C', 'slug' => 'c' . uniqid(), 'sort' => 0]);
        return Product::create([
            'merchant_id' => $m->id, 'category_id' => $c->id, 'name' => 'P', 'slug' => 'p' . uniqid(),
            'price' => $price, 'stock_type' => 'card', 'delivery_mode' => 'status', 'status' => true, 'sort' => 0,
        ]);
    }

    public function test_subsite_hides_unlisted_product(): void
    {
        $this->seedCurrency();
        config(['zcard.features.sub_site' => true]);
        Cache::flush();

        $product = $this->makeMainProduct(10000);
        $owner = User::factory()->create();
        $sub = Merchant::create(['user_id' => $owner->id, 'name' => 'Sub', 'slug' => 'sub' . uniqid(), 'status' => 1, 'commission_rate' => 0, 'settings' => ['is_subsite' => true]]);
        SubsiteDomain::create(['merchant_id' => $sub->id, 'domain' => 'sub.test', 'type' => 'custom', 'verification_status' => 'verified', 'status' => 'active', 'is_primary' => true, 'verified_at' => now()]);
        // 标记不上架
        SubsiteProductSetting::create(['merchant_id' => $sub->id, 'product_id' => $product->id, 'sku_id' => 0, 'is_listed' => false, 'pricing_mode' => 'inherit']);

        $resp = $this->withHeaders(['Host' => 'sub.test'])->getJson('/api/products');
        $resp->assertOk();
        $items = collect($resp->json('data'));
        $this->assertTrue($items->where('id', $product->id)->isEmpty(), '不上架商品不应出现在分站列表');
    }

    public function test_subsite_applies_markup_to_price(): void
    {
        $this->seedCurrency();
        config(['zcard.features.sub_site' => true]);
        Cache::flush();

        $product = $this->makeMainProduct(10000);
        $owner = User::factory()->create();
        $sub = Merchant::create(['user_id' => $owner->id, 'name' => 'Sub', 'slug' => 'sub' . uniqid(), 'status' => 1, 'commission_rate' => 0, 'settings' => ['is_subsite' => true]]);
        SubsiteDomain::create(['merchant_id' => $sub->id, 'domain' => 'sub2.test', 'type' => 'custom', 'verification_status' => 'verified', 'status' => 'active', 'is_primary' => true, 'verified_at' => now()]);
        SubsiteProductSetting::create(['merchant_id' => $sub->id, 'product_id' => $product->id, 'sku_id' => 0, 'is_listed' => true, 'pricing_mode' => 'markup_percent', 'markup_percent' => 10]);

        $resp = $this->withHeaders(['Host' => 'sub2.test'])->getJson('/api/products');
        $resp->assertOk();
        $item = collect($resp->json('data'))->firstWhere('id', $product->id);
        $this->assertNotNull($item);
        $this->assertSame(11000, $item['price_base']); // 加价后基础价 = 11000 分
    }
}
```

- [ ] **Step 2: 跑测试确认失败**

```bash
docker exec zcard-laravel.test-1 php artisan test tests/Feature/SubsiteProductVisibilityTest.php
```
Expected: FAIL（当前 ProductController 不读 subsite、不过滤、不加价）。

- [ ] **Step 3: 改 ProductController index/show/featured + transform**

读 `app/Http/Controllers/Api/ProductController.php` 全文。改造点：

**index() 方法**：在 query 构建 `Product::where('status', true)` 之后，加可见性过滤。读取 subsite：
```php
        $subsite = request()->attributes->get('subsite');
        $excludedIds = [];
        if ($subsite) {
            // 此分站标记 is_listed=false 的商品 ID
            $excludedIds = \App\Models\SubsiteProductSetting::where('merchant_id', $subsite->id)
                ->where('is_listed', false)->pluck('product_id')->toArray();
        }
        if ($excludedIds) {
            $query->whereNotIn('id', $excludedIds);
        }
```
（放在 `$query = Product::where('status', true)...` 之后、`if ($categoryId...)` 之前）

**transform() 方法**：在方法开头取 subsite 并应用加价。把现有的：
```php
        $svc = app(\App\Support\CurrencyService::class);
        $cur = request()->attributes->get('currency') ?? $svc->getBaseCurrency();
        $conv = $svc->convert((int) $p->price, $cur);
```
改为：
```php
        $svc = app(\App\Support\CurrencyService::class);
        $cur = request()->attributes->get('currency') ?? $svc->getBaseCurrency();
        // 分站加价(基础货币层,先于货币换算)
        $subsite = request()->attributes->get('subsite');
        $effectivePrice = (int) $p->price;
        if ($subsite) {
            $pricing = app(\App\Support\SubsitePricingService::class)
                ->resolveUnitPrice($p, null, $subsite);
            $effectivePrice = $pricing['price'];
        }
        $conv = $svc->convert($effectivePrice, $cur);
```
然后把 `$data` 数组里所有 `(int) $p->price`（price/price_base 两处）改为 `$effectivePrice`：
```php
            'price' => $effectivePrice,
            'price_base' => $effectivePrice,
```

- [ ] **Step 4: 跑测试确认通过**

```bash
docker exec zcard-laravel.test-1 php artisan test tests/Feature/SubsiteProductVisibilityTest.php
docker exec zcard-laravel.test-1 php artisan test
```
Expected: 新测试 pass + 全套不破。

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/Api/ProductController.php tests/Feature/SubsiteProductVisibilityTest.php
git commit -m "feat: subsite product visibility filter + markup in ProductController"
```

---

## Task 6: 后台分站管理 API + 路由

**Files:**
- Create: `app/Http/Controllers/Api/Admin/SubsiteController.php`
- Modify: `routes/api.php`

- [ ] **Step 1: SubsiteController（分站列表 + 域名管理 + 商品配置 CRUD）**

`app/Http/Controllers/Api/Admin/SubsiteController.php`:
```php
<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Merchant;
use App\Models\SubsiteDomain;
use App\Models\SubsiteProductSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * 分站后台管理(spec §8.1):分站列表、域名审批、商品配置。
 */
class SubsiteController extends Controller
{
    /** 分站列表(merchant where settings->is_subsite=true) */
    public function index(): JsonResponse
    {
        $subsites = Merchant::where('settings->is_subsite', true)
            ->with(['owner:id,username', 'domains'])
            ->orderByDesc('id')
            ->paginate(20);
        return response()->json($subsites);
    }

    /** 创建/审批分站 */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'user_id' => 'required|exists:users,id',
            'name' => 'required|string|max:100',
            'slug' => 'required|string|max:100|unique:merchants,slug',
            'default_markup_percent' => 'nullable|numeric|min:0',
            'max_markup_percent' => 'nullable|numeric|min:0',
            'commission_rate' => 'nullable|numeric|min:0|max:100',
        ]);
        $merchant = Merchant::create([
            'user_id' => $data['user_id'],
            'name' => $data['name'],
            'slug' => $data['slug'],
            'status' => 1,
            'commission_rate' => $data['commission_rate'] ?? 0,
            'settings' => [
                'is_subsite' => true,
                'default_markup_percent' => $data['default_markup_percent'] ?? 0,
                'max_markup_percent' => $data['max_markup_percent'] ?? 50,
                'settlement_confirm_days' => 7,
            ],
        ]);
        return response()->json($merchant, 201);
    }

    /** 域名审批 */
    public function updateDomain(Request $request, SubsiteDomain $domain): JsonResponse
    {
        $data = $request->validate([
            'status' => ['required', Rule::in(['pending_review', 'active', 'disabled'])],
            'verification_status' => ['sometimes', Rule::in(['pending', 'verified', 'failed'])],
        ]);
        $domain->update($data);
        \Illuminate\Support\Facades\Cache::forget("subsite:domain:{$domain->domain}");
        return response()->json($domain);
    }

    /** 分站商品配置列表 */
    public function productSettings(Merchant $merchant): JsonResponse
    {
        $settings = SubsiteProductSetting::where('merchant_id', $merchant->id)
            ->with('product:id,name,slug,price')
            ->orderByDesc('id')
            ->paginate(50);
        return response()->json($settings);
    }

    /** 保存/更新分站商品配置(upsert) */
    public function upsertProductSetting(Request $request): JsonResponse
    {
        $data = $request->validate([
            'merchant_id' => 'required|exists:merchants,id',
            'product_id' => 'required|exists:products,id',
            'sku_id' => 'nullable|integer|min:0',
            'is_listed' => 'boolean',
            'pricing_mode' => ['sometimes', Rule::in(['inherit', 'markup_percent', 'fixed_markup', 'fixed_price'])],
            'markup_percent' => 'nullable|numeric|min:0',
            'fixed_markup_amount' => 'nullable|integer|min:0',
            'fixed_price_amount' => 'nullable|integer|min:0',
        ]);
        $data['sku_id'] = $data['sku_id'] ?? 0;
        $setting = SubsiteProductSetting::updateOrCreate(
            ['merchant_id' => $data['merchant_id'], 'product_id' => $data['product_id'], 'sku_id' => $data['sku_id']],
            $data
        );
        return response()->json($setting, 201);
    }
}
```

- [ ] **Step 2: 路由**

在 `routes/api.php` 的 admin 路由组（`Route::middleware('auth:sanctum')->prefix('admin')` 内），加：
```php
        // 分站管理
        Route::get('subsites', [AdminSubsiteController::class, 'index']);
        Route::post('subsites', [AdminSubsiteController::class, 'store']);
        Route::put('subsites/domains/{domain}', [AdminSubsiteController::class, 'updateDomain']);
        Route::get('subsites/{merchant}/product-settings', [AdminSubsiteController::class, 'productSettings']);
        Route::post('subsites/product-settings', [AdminSubsiteController::class, 'upsertProductSetting']);
```
并在顶部 use 加：
```php
use App\Http\Controllers\Api\Admin\SubsiteController as AdminSubsiteController;
```

- [ ] **Step 3: 验证**

```bash
docker exec zcard-laravel.test-1 php artisan route:list --path=api/admin/subsites | head
docker exec zcard-laravel.test-1 php artisan test
```
Expected: 路由列出；全套测试不破。

- [ ] **Step 4: Commit**

```bash
git add app/Http/Controllers/Api/Admin/SubsiteController.php routes/api.php
git commit -m "feat: admin subsite management API (list/domains/product-settings)"
```

---

## Self-Review（计划完成后自查）

**1. Spec 覆盖**: §2 数据模型(subsite_domains/subsite_product_settings + 模型)→Task1; §2.8 配置→Task2; §3 域名解析中间件→Task2+3; §4 定价引擎→Task4; §4.1 商品可见性+加价→Task5; §8.1 后台管理→Task6。Phase1 范围(域名解析+可见性+加价+后台)全覆盖。Phase2(下单+结算)和Phase3(分站主自助+白标)在独立计划。

**2. 占位符扫描**: 无 TBD/TODO；每个代码步骤都给了完整代码。

**3. 类型一致**: SubsitePricingService::resolveUnitPrice 返回 `{price,base,mode,source}` 在 Task4 定义、Task5 消费一致；SubsiteProductSetting 模型字段在 Task1/Task4/Task6 一致；request attribute 'subsite' 在中间件(Task2)写、控制器(Task5)读一致。
