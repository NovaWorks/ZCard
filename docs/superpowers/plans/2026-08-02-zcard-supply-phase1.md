# 货源对接 Phase 1（数据模型 + 配置开关 + 驱动抽象）实施计划

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** 为货源对接功能铺设地基：6 张新表 + products/orders 改动、config/zcard.php 开关、app/Supply/ 驱动抽象（接口 + 3 驱动 + DTO + Manager），以及 HMAC 签名工具。本阶段不接路由/控制器/前端，产出可独立测试的底层组件。

**Architecture:** 数据模型参照现有 subsite_* 表（FK 用 foreignId+constrained、金额用 bigInteger 分、enum/comment 中文化、idempotency_key unique）。驱动抽象参照 app/Payment/Contracts + Drivers（接口 + configSchema 自描述）。HMAC 工具供 Phase 2 供货 API 中间件和回调复用。

**Tech Stack:** Laravel 13.8, PHP 8.3, PHPUnit 12（RefreshDatabase，app() 解析 service），Guzzle（已在依赖中，composer.json require guzzlehttp/guzzle 经 laravel-pay 传递）。

**测试策略:** PHPUnit Feature 测试驱动（TDD）。每个驱动方法 + DTO 映射 + HMAC 工具有独立测试。测试用 `config(['zcard.features.supply' => true])` 开启功能。

**Spec:** `docs/superpowers/specs/2026-08-02-zcard-supply-integration-design.md`（§2 数据模型、§3 驱动接口、§8.5 配置开关、§8.3 金额、§4.2 HMAC）

---

## Task 1: 配置开关（config/zcard.php + .env 示例）

**Files:**
- Modify: `config/zcard.php`
- Modify: `.env.example`

- [ ] **Step 1: 在 config/zcard.php 加 supply 配置块**

读取当前 `config/zcard.php`，在 `features` 数组里加一项，并在文件末尾加独立的 `supply` 块：

在 `'features' => [...]` 数组内追加（与 `sub_site` 同级）：
```php
        'supply' => env('ZCARD_SUPPLY', false), // 货源对接总开关
```

在 return 数组末尾（`'update'` 块之后）追加：
```php

    /**
     * 货源对接配置(spec §8.5)
     * - 总开关 features.supply 控制整体功能
     * - upstream_enabled: 作为下游(拿货)
     * - supplier_enabled: 作为上游(对外供货)
     * 两个方向可独立开关。
     */
    'supply' => [
        'upstream_enabled' => env('ZCARD_SUPPLY_UPSTREAM', true),
        'supplier_enabled' => env('ZCARD_SUPPLY_SUPPLIER', true),
        'nonce_store' => env('ZCARD_SUPPLY_NONCE_STORE', 'cache'), // redis|cache|database
        'rate_limit' => env('ZCARD_SUPPLY_RATE_LIMIT', 60),        // 每分钟/账号
        'timestamp_skew' => env('ZCARD_SUPPLY_TS_SKEW', 300),      // 秒,签名时间窗口
    ],
```

> 注意：`nonce_store` 默认 `cache` 而非 spec 里的 `redis`——因为本项目 CACHE_STORE=database（未确认 phpredis 可用）。Phase 2 中间件按此值选存储后端；redis 仅在显式配置时启用。

- [ ] **Step 2: 在 .env.example 加示例变量**

在 `.env.example` 末尾追加：
```env

# 货源对接(spec §8.5)
ZCARD_SUPPLY=false
ZCARD_SUPPLY_UPSTREAM=true
ZCARD_SUPPLY_SUPPLIER=true
ZCARD_SUPPLY_NONCE_STORE=cache
ZCARD_SUPPLY_RATE_LIMIT=60
ZCARD_SUPPLY_TS_SKEW=300
```

- [ ] **Step 3: 验证配置可读**

运行：
```bash
php artisan config:clear && php -r "require 'vendor/autoload.php'; \$app = require 'bootstrap/app.php'; \$app->boot(); echo 'features.supply=' . var_export(config('zcard.features.supply'), true) . PHP_EOL; echo 'supply.upstream=' . var_export(config('zcard.supply.upstream_enabled'), true) . PHP_EOL;"
```
预期输出：`features.supply=false`、`supply.upstream=true`。

- [ ] **Step 4: 提交**

```bash
git add config/zcard.php .env.example
git commit -m "feat(supply): config/zcard.php 加货源对接开关与配置块"
```

---

## Task 2: 数据库迁移 —— 6 张新表

**Files:**
- Create: `database/migrations/2026_08_02_000020_create_supply_sources_table.php`
- Create: `database/migrations/2026_08_02_000030_create_supplier_accounts_table.php`
- Create: `database/migrations/2026_08_02_000040_create_supplier_product_prices_table.php`
- Create: `database/migrations/2026_08_02_000050_create_supplier_ledger_entries_table.php`
- Create: `database/migrations/2026_08_02_000060_create_supply_orders_table.php`
- Create: `database/migrations/2026_08_02_000070_create_supply_nonces_table.php`

参照现有迁移风格（匿名类、`$table->id()`、`foreignId()->constrained()`、金额 bigInteger+comment 中文、enum+comment、unique 索引、timestamps）。

- [ ] **Step 1: supply_sources 表（货源配置）**

`database/migrations/2026_08_02_000020_create_supply_sources_table.php`:
```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('supply_sources', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100)->comment('运营起的名字,如「主站dujiao」');
            $table->string('driver', 30)->comment('驱动类型:dujiao_next|acg_faka|zcard');
            $table->string('base_url', 255)->comment('上游站点地址');
            $table->json('credentials')->comment('凭证(加密存储),结构随 driver 变');
            $table->string('status', 20)->default('active')->comment('active|disabled');
            $table->json('settings')->nullable()->comment('驱动相关开关:库存模式/同步/定价/发卡等');
            $table->timestamp('last_synced_at')->nullable()->comment('最近同步时间');
            $table->text('last_error')->nullable()->comment('最近一次同步/调用错误');
            $table->bigInteger('balance_cache')->nullable()->comment('上游余额缓存(分)');
            $table->unsignedInteger('sort')->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['status', 'driver']);
            $table->index('last_synced_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supply_sources');
    }
};
```

- [ ] **Step 2: supplier_accounts 表（对外供货账号）**

`database/migrations/2026_08_02_000030_create_supplier_accounts_table.php`:
```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('supplier_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100)->comment('账号名/公司名');
            $table->string('api_key', 64)->unique()->comment('公开标识(32位hex),可明文返回');
            $table->string('api_secret', 128)->comment('签名密钥(64位hex),加密存储');
            $table->bigInteger('balance')->default(0)->comment('预存余额(分)');
            $table->string('status', 20)->default('active')->comment('active|disabled');
            $table->string('contact')->nullable()->comment('联系方式');
            $table->text('remark')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_accounts');
    }
};
```

- [ ] **Step 3: supplier_product_prices 表（SKU 级专属定价）**

`database/migrations/2026_08_02_000040_create_supplier_product_prices_table.php`:
```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('supplier_product_prices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supplier_account_id')->constrained('supplier_accounts')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->foreignId('sku_id')->nullable()->constrained('product_skus')->cascadeOnDelete()->comment('null=商品级默认价;非null=SKU级专属价');
            $table->bigInteger('price')->default(0)->comment('给该账号的拿货价(分)');
            $table->timestamps();

            $table->unique(['supplier_account_id', 'product_id', 'sku_id'], 'uniq_supply_price');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_product_prices');
    }
};
```

> 注意 unique 复合键含 nullable `sku_id`：MySQL 中 NULL 不参与唯一约束（多条 null 允许），这正是我们要的（一个账号×商品可有多条 sku_id=null 不合理，但 MySQL 允许——Phase 2 服务层用 `firstOrCreate` 兜底防重）。SQLite（测试）行为一致。

- [ ] **Step 4: supplier_ledger_entries 表（供货预存账本）**

`database/migrations/2026_08_02_000050_create_supplier_ledger_entries_table.php`:
```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('supplier_ledger_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supplier_account_id')->constrained('supplier_accounts')->cascadeOnDelete();
            $table->foreignId('order_id')->nullable()->constrained('orders')->nullOnDelete()->comment('对应本地order,下单扣费时有');
            $table->string('type', 20)->comment('recharge(充值)|order(扣费)|refund(退款)|adjust(手动调)');
            $table->bigInteger('amount')->comment('有符号(分):正=入账,负=扣费');
            $table->bigInteger('balance_after')->comment('变动后余额快照(分)');
            $table->string('idempotency_key', 100)->unique()->comment('幂等键');
            $table->string('remark')->nullable();
            $table->timestamps();

            $table->index(['supplier_account_id', 'type']);
            $table->index('order_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_ledger_entries');
    }
};
```

- [ ] **Step 5: supply_orders 表（供货订单记录）**

`database/migrations/2026_08_02_000060_create_supply_orders_table.php`:
```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('supply_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supplier_account_id')->constrained('supplier_accounts')->cascadeOnDelete();
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete()->comment('对应本地order(source=supply)');
            $table->string('downstream_order_no', 100)->comment('下游幂等订单号');
            $table->string('fulfillment_mode', 10)->default('sync')->comment('sync|async');
            $table->string('callback_url')->nullable()->comment('下游回调地址');
            $table->string('callback_status', 20)->nullable()->comment('pending|sent|failed');
            $table->timestamps();

            $table->unique(['supplier_account_id', 'downstream_order_no'], 'uniq_supply_downstream_no');
            $table->index('order_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supply_orders');
    }
};
```

- [ ] **Step 6: supply_nonces 表（防重放兜底）**

`database/migrations/2026_08_02_000070_create_supply_nonces_table.php`:
```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('supply_nonces', function (Blueprint $table) {
            $table->id();
            $table->string('nonce', 64)->unique()->comment('随机串,防重放');
            $table->timestamp('expires_at')->comment('过期时间,5分钟后');
            $table->timestamps();

            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supply_nonces');
    }
};
```

- [ ] **Step 7: 运行迁移验证**

运行：
```bash
php artisan migrate
```
预期：6 张表创建成功，无报错。

- [ ] **Step 8: 提交**

```bash
git add database/migrations/2026_08_02_0000*.php
git commit -m "feat(supply): 货源对接6张新表迁移(supply_sources/supplier_accounts/prices/ledger/orders/nonces)"
```

---

## Task 3: products 与 orders 表改动

**Files:**
- Create: `database/migrations/2026_08_02_000080_add_upstream_fields_to_products_table.php`
- Create: `database/migrations/2026_08_02_000090_add_supply_fields_to_orders_table.php`
- Modify: `app/Models/Product.php`
- Modify: `app/Models/Order.php`

- [ ] **Step 1: products 表加上游来源字段**

`database/migrations/2026_08_02_000080_add_upstream_fields_to_products_table.php`:
```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->foreignId('upstream_source_id')->nullable()->after('merchant_id')
                ->constrained('supply_sources')->nullOnDelete()->comment('来源货源,null=本地自营');
            $table->string('upstream_product_code')->nullable()->after('upstream_source_id')
                ->comment('上游商品标识(acg-faka code/dujiao sku_id/zcard slug)');
            $table->timestamp('upstream_synced_at')->nullable()->after('upstream_product_code')
                ->comment('最近一次上游同步时间');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['upstream_source_id', 'upstream_product_code', 'upstream_synced_at']);
        });
    }
};
```

> 注意：`constrained('supply_sources')` 加外键。若 products 已有数据且 supply_sources 为空，nullable FK 允许 NULL 不影响。

- [ ] **Step 2: orders 表加 source/upstream 字段**

`database/migrations/2026_08_02_000090_add_supply_fields_to_orders_table.php`:
```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('source', 20)->nullable()->after('subsite_profit')
                ->comment('supply=该单由供货API下单产生;null=正常顾客单');
            $table->string('upstream_order_id')->nullable()->after('source')
                ->comment('作为下游拿货时,上游返回的订单号');
            $table->foreignId('upstream_source_id')->nullable()->after('upstream_order_id')
                ->constrained('supply_sources')->nullOnDelete()->comment('作为下游拿货时,货源来源');

            $table->index('source');
            $table->index('upstream_source_id');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex(['source']);
            $table->dropIndex(['upstream_source_id']);
            $table->dropColumn(['source', 'upstream_order_id', 'upstream_source_id']);
        });
    }
};
```

- [ ] **Step 3: 运行迁移**

运行：
```bash
php artisan migrate
```
预期：products、orders 加列成功。

- [ ] **Step 4: 更新 Product 模型 fillable + casts**

读取 `app/Models/Product.php`，在 `$fillable` 数组里（合适位置，如 `merchant_id` 之后）追加：
```php
        'upstream_source_id', 'upstream_product_code', 'upstream_synced_at',
```
在 `casts()` 方法返回数组里追加：
```php
            'upstream_synced_at' => 'datetime',
```

- [ ] **Step 5: 更新 Order 模型 fillable**

读取 `app/Models/Order.php`，在 `$fillable` 数组里追加：
```php
        'source', 'upstream_order_id', 'upstream_source_id',
```

- [ ] **Step 6: 提交**

```bash
git add database/migrations/2026_08_02_00008*.php database/migrations/2026_08_02_00009*.php app/Models/Product.php app/Models/Order.php
git commit -m "feat(supply): products/orders 加上游来源字段 + 模型fillable"
```

---

## Task 4: 新增 Eloquent 模型（5 个）

**Files:**
- Create: `app/Models/SupplySource.php`
- Create: `app/Models/SupplierAccount.php`
- Create: `app/Models/SupplierProductPrice.php`
- Create: `app/Models/SupplierLedgerEntry.php`
- Create: `app/Models/SupplyOrder.php`
- Create: `app/Models/SupplyNonce.php`

参照现有模型风格（`protected $fillable` 数组、`casts()` 方法、BelongsTo/HasMany 关系带返回类型）。

- [ ] **Step 1: SupplySource 模型**

`app/Models/SupplySource.php`:
```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class SupplySource extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'name', 'driver', 'base_url', 'credentials', 'status', 'settings',
        'last_synced_at', 'last_error', 'balance_cache', 'sort',
    ];

    protected function casts(): array
    {
        return [
            'credentials' => 'encrypted:array', // 加密存储的 json,自动加解密
            'settings' => 'array',
            'last_synced_at' => 'datetime',
            'balance_cache' => 'integer',
        ];
    }

    public const DRIVER_DUJIAO_NEXT = 'dujiao_next';
    public const DRIVER_ACG_FAKA = 'acg_faka';
    public const DRIVER_ZCARD = 'zcard';

    public function products(): HasMany
    {
        return $this->hasMany(Product::class, 'upstream_source_id');
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }
}
```

> 关键：`'credentials' => 'encrypted:array'` 是 Laravel 内建 cast，存时自动 `Crypt::encryptString(json_encode())`，读时自动解密+反序列化。一步满足 spec §6.3 的加密存储要求。

- [ ] **Step 2: SupplierAccount 模型**

`app/Models/SupplierAccount.php`:
```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class SupplierAccount extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'name', 'api_key', 'api_secret', 'balance', 'status', 'contact', 'remark',
    ];

    protected function casts(): array
    {
        return [
            'balance' => 'integer',
        ];
    }

    public const STATUS_ACTIVE = 'active';
    public const STATUS_DISABLED = 'disabled';

    public function productPrices(): HasMany
    {
        return $this->hasMany(SupplierProductPrice::class);
    }

    public function ledgerEntries(): HasMany
    {
        return $this->hasMany(SupplierLedgerEntry::class);
    }

    public function supplyOrders(): HasMany
    {
        return $this->hasMany(SupplyOrder::class);
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }
}
```

> 注意：`api_secret` 不放任何 hidden/cast——它存储时由服务层加密（Phase 2 Task），这里存的是密文。但 `encrypted:array` 不适用（它是字符串不是 json）。Phase 2 的 SupplyAccountService 创建时用 `Crypt::encryptString()`。为避免遗漏，这里加 `$hidden`：
```php
    protected $hidden = ['api_secret'];
```
（API 返回时默认不暴露，需要时显式 `makeVisible`。）

修正上面模型，加 `$hidden`：
```php
    protected $hidden = ['api_secret'];
```

- [ ] **Step 3: SupplierProductPrice 模型**

`app/Models/SupplierProductPrice.php`:
```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SupplierProductPrice extends Model
{
    protected $fillable = [
        'supplier_account_id', 'product_id', 'sku_id', 'price',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'integer',
            'sku_id' => 'integer',
        ];
    }

    public function supplierAccount(): BelongsTo
    {
        return $this->belongsTo(SupplierAccount::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function sku(): BelongsTo
    {
        return $this->belongsTo(ProductSku::class);
    }
}
```

- [ ] **Step 4: SupplierLedgerEntry 模型**

`app/Models/SupplierLedgerEntry.php`:
```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SupplierLedgerEntry extends Model
{
    protected $fillable = [
        'supplier_account_id', 'order_id', 'type', 'amount', 'balance_after',
        'idempotency_key', 'remark',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'balance_after' => 'integer',
        ];
    }

    public const TYPE_RECHARGE = 'recharge';
    public const TYPE_ORDER = 'order';
    public const TYPE_REFUND = 'refund';
    public const TYPE_ADJUST = 'adjust';

    public function supplierAccount(): BelongsTo
    {
        return $this->belongsTo(SupplierAccount::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
```

- [ ] **Step 5: SupplyOrder 模型**

`app/Models/SupplyOrder.php`:
```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SupplyOrder extends Model
{
    protected $fillable = [
        'supplier_account_id', 'order_id', 'downstream_order_no',
        'fulfillment_mode', 'callback_url', 'callback_status',
    ];

    public const MODE_SYNC = 'sync';
    public const MODE_ASYNC = 'async';

    public const CALLBACK_PENDING = 'pending';
    public const CALLBACK_SENT = 'sent';
    public const CALLBACK_FAILED = 'failed';

    public function supplierAccount(): BelongsTo
    {
        return $this->belongsTo(SupplierAccount::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
```

- [ ] **Step 6: SupplyNonce 模型**

`app/Models/SupplyNonce.php`:
```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SupplyNonce extends Model
{
    protected $fillable = ['nonce', 'expires_at'];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
        ];
    }
}
```

- [ ] **Step 7: 写模型基础测试验证可创建**

`tests/Feature/SupplyModelsTest.php`:
```php
<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\SupplierAccount;
use App\Models\SupplierLedgerEntry;
use App\Models\SupplySource;
use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

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

    public function test_supplier_account_balance_default_zero(): void
    {
        $account = SupplierAccount::create([
            'name' => '下游A',
            'api_key' => 'k' . uniqid(),
            'api_secret' => 'encrypted_secret',
        ]);
        $this->assertSame(0, (int) $account->fresh()->balance);
        $this->assertTrue($account->isActive());
    }

    public function test_supplier_ledger_entry_create(): void
    {
        $account = SupplierAccount::create([
            'name' => '下游A', 'api_key' => 'k' . uniqid(), 'api_secret' => 's',
        ]);
        $entry = SupplierLedgerEntry::create([
            'supplier_account_id' => $account->id,
            'type' => SupplierLedgerEntry::TYPE_RECHARGE,
            'amount' => 10000,
            'balance_after' => 10000,
            'idempotency_key' => 'recharge_' . $account->id . '_1',
            'remark' => '首次充值',
        ]);
        $this->assertDatabaseHas('supplier_ledger_entries', ['id' => $entry->id, 'amount' => 10000]);
    }
}
```

- [ ] **Step 8: 运行测试**

运行：
```bash
php artisan test --filter=SupplyModelsTest
```
预期：3 个测试通过。

- [ ] **Step 9: 提交**

```bash
git add app/Models/Supply*.php app/Models/Supplier*.php tests/Feature/SupplyModelsTest.php
git commit -m "feat(supply): 5个Eloquent模型 + credentials加密cast + 基础测试"
```

---

## Task 5: HMAC 签名工具

**Files:**
- Create: `app/Supply/HmacSigner.php`
- Create: `tests/Feature/HmacSignerTest.php`

spec §4.2 签名算法：`signString = METHOD\nPATH\ntimestamp\nnonce\nmd5(body)`，`signature = hex_lower(HMAC_SHA256(secret, signString))`。

- [ ] **Step 1: 写失败测试**

`tests/Feature/HmacSignerTest.php`:
```php
<?php

namespace Tests\Feature;

use App\Supply\HmacSigner;
use Tests\TestCase;

class HmacSignerTest extends TestCase
{
    public function test_sign_and_verify_match(): void
    {
        $secret = 'test_secret_key';
        $signString = HmacSigner::buildSignString('POST', '/api/supply/orders', '1700000000', 'abc123', md5(''));
        $signature = HmacSigner::sign($secret, $signString);

        $this->assertTrue(HmacSigner::verify($secret, $signString, $signature));
        $this->assertFalse(HmacSigner::verify($secret, $signString, 'tampered_signature'));
    }

    public function test_build_sign_string_format(): void
    {
        $signString = HmacSigner::buildSignString('POST', '/api/supply/orders', '1700000000', 'n1', md5('{"a":1}'));

        $this->assertSame("POST\n/api/supply/orders\n1700000000\nn1\n" . md5('{"a":1}'), $signString);
    }

    public function test_path_excludes_query_string(): void
    {
        $signString = HmacSigner::buildSignString('GET', '/api/supply/products', '1', 'n', md5(''));
        // 验证 path 不含 query(query 由调用方在 buildSignString 前剥离)
        $this->assertStringContainsString("/api/supply/products\n", $signString);
    }

    public function test_timestamp_within_skew(): void
    {
        $skew = 300;
        $now = time();

        $this->assertTrue(HmacSigner::timestampValid($now, $skew));
        $this->assertTrue(HmacSigner::timestampValid($now + 100, $skew));
        $this->assertFalse(HmacSigner::timestampValid($now + 400, $skew));
        $this->assertFalse(HmacSigner::timestampValid($now - 400, $skew));
    }
}
```

- [ ] **Step 2: 运行测试确认失败**

运行：
```bash
php artisan test --filter=HmacSignerTest
```
预期：FAIL（类不存在）。

- [ ] **Step 3: 实现 HmacSigner**

`app/Supply/HmacSigner.php`:
```php
<?php

namespace App\Supply;

/**
 * 货源对接 HMAC-SHA256 签名工具(spec §4.2)
 * 供货API鉴权 + 回调签名共用。
 *
 * 签名串 = METHOD\nPATH(不含query)\ntimestamp\nnonce\nmd5(body)
 * 签名 = hex_lower(HMAC_SHA256(api_secret, 签名串))
 */
class HmacSigner
{
    /**
     * 构建签名串。PATH 必须不含 query string(调用方传参前剥离)。
     */
    public static function buildSignString(string $method, string $path, string $timestamp, string $nonce, string $bodyMd5): string
    {
        return implode("\n", [$method, $path, $timestamp, $nonce, $bodyMd5]);
    }

    /**
     * 计算签名(hex 小写)。
     */
    public static function sign(string $secret, string $signString): string
    {
        return hash_hmac('sha256', $signString, $secret);
    }

    /**
     * 常数时间比较验签。
     */
    public static function verify(string $secret, string $signString, string $signature): bool
    {
        $expected = self::sign($secret, $signString);
        return hash_equals($expected, $signature);
    }

    /**
     * 检查 timestamp 是否在 ±skew 窗口内(spec §8.5 timestamp_skew)。
     */
    public static function timestampValid(int $timestamp, int $skew): bool
    {
        return abs(time() - $timestamp) <= $skew;
    }
}
```

- [ ] **Step 4: 运行测试确认通过**

运行：
```bash
php artisan test --filter=HmacSignerTest
```
预期：4 个测试通过。

- [ ] **Step 5: 提交**

```bash
git add app/Supply/HmacSigner.php tests/Feature/HmacSignerTest.php
git commit -m "feat(supply): HMAC-SHA256签名工具(buildSignString/sign/verify/timestampValid)"
```

---

## Task 6: DTO 数据结构（屏蔽三家上游差异）

**Files:**
- Create: `app/Supply/Dto/UpstreamCategory.php`
- Create: `app/Supply/Dto/UpstreamProduct.php`
- Create: `app/Supply/Dto/UpstreamOrder.php`
- Create: `app/Supply/Dto/UpstreamFulfillment.php`

spec §3.4 定义。用只读 PHP 类（构造器提升）。

- [ ] **Step 1: UpstreamCategory**

`app/Supply/Dto/UpstreamCategory.php`:
```php
<?php

namespace App\Supply\Dto;

/** 上游分类(驱动统一输出) */
class UpstreamCategory
{
    public function __construct(
        public readonly string $code,
        public readonly string $name,
        public readonly ?string $parentCode = null,
        public readonly ?string $slug = null,
        public readonly ?string $icon = null,
        public readonly int $sort = 0,
    ) {}
}
```

- [ ] **Step 2: UpstreamProduct**

`app/Supply/Dto/UpstreamProduct.php`:
```php
<?php

namespace App\Supply\Dto;

/** 上游商品(驱动统一输出,金额已转为分) */
class UpstreamProduct
{
    /**
     * @param  array<int, array{code:?string, name:string, price:int, stock_quantity:int, is_active:bool}>  $skus
     */
    public function __construct(
        public readonly string $code,
        public readonly string $name,
        public readonly int $price,              // 售价(分),驱动内部元→分转换后
        public readonly int $factoryPrice,       // 拿货价(分)
        public readonly ?string $categoryCode = null,
        public readonly ?string $description = null,
        public readonly ?string $cover = null,
        public readonly array $images = [],
        public readonly bool $isActive = true,
        public readonly array $skus = [],        // 见 @param
        public readonly int $stockQuantity = -1, // -1=无限
    ) {}
}
```

- [ ] **Step 3: UpstreamFulfillment**

`app/Supply/Dto/UpstreamFulfillment.php`:
```php
<?php

namespace App\Supply\Dto;

/** 上游发货物(卡密等) */
class UpstreamFulfillment
{
    /**
     * @param  string[]  $cards  卡密内容数组
     */
    public function __construct(
        public readonly string $type = 'auto',     // auto|manual
        public readonly string $status = 'pending', // pending|delivered
        public readonly array $cards = [],
        public readonly ?string $deliveredAt = null,
    ) {}

    public function isDelivered(): bool
    {
        return $this->status === 'delivered';
    }
}
```

- [ ] **Step 4: UpstreamOrder**

`app/Supply/Dto/UpstreamOrder.php`:
```php
<?php

namespace App\Supply\Dto;

use App\Supply\Dto\UpstreamFulfillment;

/** 上游订单(驱动统一输出) */
class UpstreamOrder
{
    public function __construct(
        public readonly string $id,                // 上游订单号
        public readonly string $status,            // pending|paid|delivered|canceled
        public readonly int $amount,               // 实付(分)
        public readonly string $currency = 'CNY',
        public readonly ?UpstreamFulfillment $fulfillment = null,
    ) {}
}
```

- [ ] **Step 5: 提交**

```bash
git add app/Supply/Dto/
git commit -m "feat(supply): 4个DTO(UpstreamCategory/Product/Order/Fulfillment)统一上游数据结构"
```

---

## Task 7: SupplyDriver 接口 + SupplyManager 工厂

**Files:**
- Create: `app/Supply/Contracts/SupplyDriver.php`
- Create: `app/Supply/SupplyManager.php`
- Create: `tests/Feature/SupplyManagerTest.php`

参照 `app/Payment/Contracts/PaymentDriver.php` 风格。

- [ ] **Step 1: SupplyDriver 接口**

`app/Supply/Contracts/SupplyDriver.php`:
```php
<?php

namespace App\Supply\Contracts;

use App\Models\SupplySource;
use App\Supply\Dto\UpstreamProduct;
use App\Supply\Dto\UpstreamOrder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * 货源驱动统一接口(spec §3.1)
 * 三家上游(dujiao_next/acg_faka/zcard)各一实现,上层透明调用。
 */
interface SupplyDriver
{
    /**
     * 驱动自描述:声明它需要的配置字段(表单按 schema 动态渲染)。
     * 返回 ['field_key' => ['type'=>'text|number|url|secret','label'=>'中文','required'=>bool,'help'=>'?']]
     */
    public static function configSchema(): array;

    /** 驱动展示名/图标,用于后台下拉 */
    public static function info(): array;

    /** 用 SupplySource 实例化驱动 */
    public function __construct(SupplySource $source);

    /** 测连通 + 返回 ['connected'=>bool,'name'=>?string,'balance'=>?int(分),'currency'=>?string,'error'=>?string] */
    public function ping(): array;

    /** @return array<int, \App\Supply\Dto\UpstreamCategory> */
    public function listCategories(): array;

    /**
     * 分页拉商品。
     * @param  Carbon|null  $updatedAfter  增量同步时传,全量传 null
     * @return array{items:UpstreamProduct[], total:int, page:int, has_more:bool}
     */
    public function listProducts(?Carbon $updatedAfter, int $page): array;

    public function getProduct(string $code): ?UpstreamProduct;

    /** 库存数,-1=无限 */
    public function getStock(string $code, ?string $skuCode = null): int;

    /**
     * 下单拿货。
     * @param  array{product_code:string,sku_code:?string,quantity:int,downstream_order_no:string,contact:?string,callback_url:?string}  $params
     */
    public function createOrder(array $params): UpstreamOrder;

    public function getOrder(string $upstreamOrderId): UpstreamOrder;

    public function cancelOrder(string $upstreamOrderId): bool;

    /** 接收上游异步回调:验签+解析,返回标准化数组或 null */
    public function verifyCallback(Request $request): ?array;
}
```

- [ ] **Step 2: SupplyManager 工厂**

`app/Supply/SupplyManager.php`:
```php
<?php

namespace App\Supply;

use App\Models\SupplySource;
use App\Supply\Contracts\SupplyDriver;
use App\Supply\Drivers\AcgFakaDriver;
use App\Supply\Drivers\DujiaoNextDriver;
use App\Supply\Drivers\ZCardDriver;
use InvalidArgumentException;

/**
 * 货源驱动工厂(spec §3.1)
 * 按 SupplySource.driver 返回对应驱动实例。
 */
class SupplyManager
{
    /** driver 标识 → 驱动类 */
    public const DRIVERS = [
        SupplySource::DRIVER_DUJIAO_NEXT => DujiaoNextDriver::class,
        SupplySource::DRIVER_ACG_FAKA => AcgFakaDriver::class,
        SupplySource::DRIVER_ZCARD => ZCardDriver::class,
    ];

    public function driver(SupplySource $source): SupplyDriver
    {
        $class = self::DRIVERS[$source->driver] ?? null;
        if (! $class) {
            throw new InvalidArgumentException("未知货源驱动: {$source->driver}");
        }

        return new $class($source);
    }

    /** 所有可用驱动的 info + configSchema(供后台表单渲染) */
    public static function allDriversMeta(): array
    {
        $meta = [];
        foreach (self::DRIVERS as $key => $class) {
            $meta[] = [
                'driver' => $key,
                'name' => $class::info()['name'] ?? $key,
                'icon' => $class::info()['icon'] ?? null,
                'config_schema' => $class::configSchema(),
            ];
        }

        return $meta;
    }
}
```

- [ ] **Step 3: 写失败测试**

`tests/Feature/SupplyManagerTest.php`:
```php
<?php

namespace Tests\Feature;

use App\Models\SupplySource;
use App\Supply\Drivers\AcgFakaDriver;
use App\Supply\Drivers\DujiaoNextDriver;
use App\Supply\Drivers\ZCardDriver;
use App\Supply\SupplyManager;
use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;

class SupplyManagerTest extends TestCase
{
    use RefreshDatabase;

    public function test_driver_returns_correct_instance(): void
    {
        $dujiao = SupplySource::create([
            'name' => 'd', 'driver' => SupplySource::DRIVER_DUJIAO_NEXT,
            'base_url' => 'https://a.com', 'credentials' => [], 'status' => 'active',
        ]);
        $acg = SupplySource::create([
            'name' => 'a', 'driver' => SupplySource::DRIVER_ACG_FAKA,
            'base_url' => 'https://b.com', 'credentials' => [], 'status' => 'active',
        ]);
        $zcard = SupplySource::create([
            'name' => 'z', 'driver' => SupplySource::DRIVER_ZCARD,
            'base_url' => 'https://c.com', 'credentials' => [], 'status' => 'active',
        ]);

        $this->assertInstanceOf(DujiaoNextDriver::class, app(SupplyManager::class)->driver($dujiao));
        $this->assertInstanceOf(AcgFakaDriver::class, app(SupplyManager::class)->driver($acg));
        $this->assertInstanceOf(ZCardDriver::class, app(SupplyManager::class)->driver($zcard));
    }

    public function test_unknown_driver_throws(): void
    {
        $source = SupplySource::create([
            'name' => 'x', 'driver' => 'nonexistent',
            'base_url' => 'https://x.com', 'credentials' => [], 'status' => 'active',
        ]);

        $this->expectException(InvalidArgumentException::class);
        app(SupplyManager::class)->driver($source);
    }

    public function test_all_drivers_meta_returns_schema(): void
    {
        $meta = SupplyManager::allDriversMeta();

        $this->assertCount(3, $meta);
        $drivers = array_column($meta, 'driver');
        $this->assertContains(SupplySource::DRIVER_DUJIAO_NEXT, $drivers);
        $this->assertContains(SupplySource::DRIVER_ACG_FAKA, $drivers);
        $this->assertContains(SupplySource::DRIVER_ZCARD, $drivers);

        // 每个都有 config_schema
        foreach ($meta as $m) {
            $this->assertArrayHasKey('config_schema', $m);
            $this->assertArrayHasKey('base_url', $m['config_schema']);
        }
    }
}
```

- [ ] **Step 4: 运行测试确认失败**

运行：
```bash
php artisan test --filter=SupplyManagerTest
```
预期：FAIL（驱动类不存在）。这是预期的——下个 Task 创建驱动类。

- [ ] **Step 5: 提交**

```bash
git add app/Supply/Contracts/SupplyDriver.php app/Supply/SupplyManager.php tests/Feature/SupplyManagerTest.php
git commit -m "feat(supply): SupplyDriver接口 + SupplyManager工厂(驱动待实现)"
```

---

## Task 8: 三个驱动实现 —— configSchema + info + 构造

**Files:**
- Create: `app/Supply/Drivers/DujiaoNextDriver.php`
- Create: `app/Supply/Drivers/AcgFakaDriver.php`
- Create: `app/Supply/Drivers/ZCardDriver.php`

本 Task 只实现 `configSchema()`、`info()`、构造方法（HTTP 调用留到 Phase 3，因为依赖路由/控制器）。先让 Phase 1 的 SupplyManager 测试通过。

- [ ] **Step 1: DujiaoNextDriver（骨架）**

`app/Supply/Drivers/DujiaoNextDriver.php`:
```php
<?php

namespace App\Supply\Drivers;

use App\Models\SupplySource;
use App\Supply\Contracts\SupplyDriver;
use App\Supply\Dto\UpstreamOrder;
use App\Supply\Dto\UpstreamProduct;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * dujiao-next 上游驱动(spec §3.3)
 * 鉴权:HMAC-SHA256,三头 Dujiao-Next-Api-Key/Timestamp/Signature
 * 端点:/api/v1/upstream/*
 * HTTP 调用实现见 Phase 3 Task。
 */
class DujiaoNextDriver implements SupplyDriver
{
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

    public function ping(): array { return $this->notImplemented('ping'); }
    public function listCategories(): array { return $this->notImplemented('listCategories'); }
    public function listProducts(?Carbon $updatedAfter, int $page): array { return $this->notImplemented('listProducts'); }
    public function getProduct(string $code): ?UpstreamProduct { return $this->notImplemented('getProduct'); }
    public function getStock(string $code, ?string $skuCode = null): int { return $this->notImplemented('getStock'); }
    public function createOrder(array $params): UpstreamOrder { return $this->notImplemented('createOrder'); }
    public function getOrder(string $upstreamOrderId): UpstreamOrder { return $this->notImplemented('getOrder'); }
    public function cancelOrder(string $upstreamOrderId): bool { return $this->notImplemented('cancelOrder'); }
    public function verifyCallback(Request $request): ?array { return $this->notImplemented('verifyCallback'); }

    /** Phase 3 实现 HTTP 调用前抛此异常 */
    private function notImplemented(string $method): mixed
    {
        throw new \RuntimeException("DujiaoNextDriver::{$method} 待 Phase 3 实现");
    }
}
```

- [ ] **Step 2: AcgFakaDriver（骨架）**

`app/Supply/Drivers/AcgFakaDriver.php`:
```php
<?php

namespace App\Supply\Drivers;

use App\Models\SupplySource;
use App\Supply\Contracts\SupplyDriver;
use App\Supply\Dto\UpstreamOrder;
use App\Supply\Dto\UpstreamProduct;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * acg-faka 上游驱动(spec §3.3)
 * 鉴权:MD5,sign=md5(ksort去空值参数+&key=app_key)
 * 端点:/shared/commodity/*
 * HTTP 调用实现见 Phase 3 Task。
 */
class AcgFakaDriver implements SupplyDriver
{
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

    public function ping(): array { return $this->notImplemented('ping'); }
    public function listCategories(): array { return $this->notImplemented('listCategories'); }
    public function listProducts(?Carbon $updatedAfter, int $page): array { return $this->notImplemented('listProducts'); }
    public function getProduct(string $code): ?UpstreamProduct { return $this->notImplemented('getProduct'); }
    public function getStock(string $code, ?string $skuCode = null): int { return $this->notImplemented('getStock'); }
    public function createOrder(array $params): UpstreamOrder { return $this->notImplemented('createOrder'); }
    public function getOrder(string $upstreamOrderId): UpstreamOrder { return $this->notImplemented('getOrder'); }
    public function cancelOrder(string $upstreamOrderId): bool { return $this->notImplemented('cancelOrder'); }
    public function verifyCallback(Request $request): ?array { return $this->notImplemented('verifyCallback'); }

    private function notImplemented(string $method): mixed
    {
        throw new \RuntimeException("AcgFakaDriver::{$method} 待 Phase 3 实现");
    }
}
```

- [ ] **Step 3: ZCardDriver（骨架）**

`app/Supply/Drivers/ZCardDriver.php`:
```php
<?php

namespace App\Supply\Drivers;

use App\Models\SupplySource;
use App\Supply\Contracts\SupplyDriver;
use App\Supply\Dto\UpstreamOrder;
use App\Supply\Dto\UpstreamProduct;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * ZCard 上游驱动(spec §3.3) —— 用于「自己对接自己」或对接另一个 ZCard 实例
 * 鉴权:本系统自定义 HMAC(同 /api/supply/* 协议)
 * 端点:/api/supply/*
 * HTTP 调用实现见 Phase 3 Task。
 */
class ZCardDriver implements SupplyDriver
{
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

    public function ping(): array { return $this->notImplemented('ping'); }
    public function listCategories(): array { return $this->notImplemented('listCategories'); }
    public function listProducts(?Carbon $updatedAfter, int $page): array { return $this->notImplemented('listProducts'); }
    public function getProduct(string $code): ?UpstreamProduct { return $this->notImplemented('getProduct'); }
    public function getStock(string $code, ?string $skuCode = null): int { return $this->notImplemented('getStock'); }
    public function createOrder(array $params): UpstreamOrder { return $this->notImplemented('createOrder'); }
    public function getOrder(string $upstreamOrderId): UpstreamOrder { return $this->notImplemented('getOrder'); }
    public function cancelOrder(string $upstreamOrderId): bool { return $this->notImplemented('cancelOrder'); }
    public function verifyCallback(Request $request): ?array { return $this->notImplemented('verifyCallback'); }

    private function notImplemented(string $method): mixed
    {
        throw new \RuntimeException("ZCardDriver::{$method} 待 Phase 3 实现");
    }
}
```

- [ ] **Step 4: 运行 SupplyManager 测试确认通过**

运行：
```bash
php artisan test --filter=SupplyManagerTest
```
预期：3 个测试通过（configSchema 返回含 base_url，driver 实例化正确）。

- [ ] **Step 5: 运行全量测试确认无回归**

运行：
```bash
php artisan test
```
预期：全部通过（原有测试 + 新增的 SupplyModels/HmacSigner/SupplyManager）。

- [ ] **Step 6: 提交**

```bash
git add app/Supply/Drivers/
git commit -m "feat(supply): 3个驱动骨架(configSchema/info/构造,HTTP调用待Phase3)"
```

---

## Phase 1 完成标准

- [ ] `config/zcard.php` 含 `features.supply` + `supply.*` 配置块
- [ ] 6 张新表迁移成功：supply_sources / supplier_accounts / supplier_product_prices / supplier_ledger_entries / supply_orders / supply_nonces
- [ ] products（upstream_source_id/code/synced_at）+ orders（source/upstream_order_id/upstream_source_id）字段已加
- [ ] 5 个 Eloquent 模型可创建，credentials 加密 cast 生效
- [ ] HmacSigner 工具 sign/verify/timestampValid 测试通过
- [ ] 4 个 DTO 类就绪
- [ ] SupplyDriver 接口 + SupplyManager 工厂 + 3 驱动骨架，SupplyManager 测试通过
- [ ] 全量 `php artisan test` 无回归

**Phase 2 将实现：** 对外供货 API（`/api/supply/*`）+ HMAC 鉴权中间件 + 供货账号管理（创建/充值/账本/专属定价）+ SupplyOrderService（供货下单发卡）。
