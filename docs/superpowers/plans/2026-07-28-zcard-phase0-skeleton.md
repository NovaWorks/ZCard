# ZCard Phase 0 — 代码骨架实现计划

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** 搭建 ZCard（现代插件制发卡系统）的 Phase 0 地基——可登录的 Filament 后台 + 空 Vue3 前台 + Laravel Sail 开发环境 + 核心 schema + RBAC 初始化命令。

**Architecture:** Laravel 13 (PHP 8.3) 单主干，Filament v5 后台，独立 Vue3 `storefront/` 前台，Laravel Sail (Docker) 开发环境。RBAC 用 spatie/laravel-permission + filament-shield。卡密用应用层加密 + hash 去重，支持大量导入。

**Tech Stack:** PHP 8.3, Laravel 13.x, Filament v5.x, spatie/laravel-permission v8, bezhansalleh/filament-shield, MySQL 8, Redis, Vue 3, Vite 5, Tailwind v4, Pinia, Vue Router 4, axios, pnpm。

**对应 spec:** `docs/superpowers/specs/2026-07-28-zcard-phase0-skeleton-design.md`（不进 git）。

---

## 关键环境前提（执行前必读）

本机（macOS）**没有 PHP / Composer / MySQL / Redis**，只有 Node 22 / npm / pnpm / Docker。因此：
- **所有 PHP/Composer/Artisan 命令都在 Sail 容器内执行**，前缀 `./vendor/bin/sail`（后文简写为 `sail`）。
- 初始项目用 `laravel.build` 的 Docker 引导脚本创建（无需本地 PHP）。
- 前端 `storefront/` 在宿主机用 pnpm 跑（不进容器）。

后续命令中 `sail xxx` = `./vendor/bin/sail xxx`（首次没有 alias 时用全路径）。

---

## 文件结构总览

执行完所有任务后的产物（仅列本计划新增/修改的关键文件）：

```
ZCard/
├── app/
│   ├── Console/Commands/InstallCommand.php        # Task 9
│   ├── Filament/Resources/
│   │   ├── UserResource.php (+ Pages/)            # Task 10
│   │   └── MerchantResource.php (+ Pages/)        # Task 10
│   ├── Http/
│   │   ├── Middleware/ForcePasswordChange.php     # Task 9
│   │   └── Controllers/Api/HealthController.php   # Task 11
│   ├── Models/                                    # Task 6-8
│   │   ├── User.php (改), Merchant.php, MerchantMember.php
│   │   ├── Category.php, Product.php, Card.php, CardImport.php
│   │   ├── Order.php, OrderItem.php, Payment.php, OrderDelivery.php
│   │   └── Setting.php
│   ├── Providers/Filament/AdminPanelProvider.php  # Task 4
│   ├── Support/CardCipher.php                     # Task 7 (加密/hash 工具)
│   └── ...
├── database/
│   ├── migrations/                                # Task 6-8 (12 张业务表)
│   └── seeders/DatabaseSeeder.php                 # Task 9 (只 seed 演示数据)
├── plugins/example-plugin/                        # Task 12
│   ├── plugin.json, src/ServiceProvider.php, README.md
├── storefront/                                    # Task 13
│   ├── src/{api,router,stores,layouts,views,components,assets}
│   ├── package.json, vite.config.ts, tailwind.config.js, tsconfig.json
├── config/zcard.php                               # Task 5 (功能开关占位)
├── .gitignore (改: 加 docs/, storefront/node_modules)  # Task 2
├── README.md                                      # Task 14
└── (Laravel 默认文件 + compose.yaml 由 Task 1 生成)
```

---

## Task 1: 用 Docker 引导创建 Laravel 13 项目 + 安装 Sail

**Files:**
- Create: 整个 Laravel 项目骨架（composer.json, artisan, app/, bootstrap/, config/, public/, routes/ 等）
- Create: `compose.yaml`（Sail 发布）
- Create: `.env`（Sail 生成）

由于本机无 PHP/Composer，用 `laravel.build` 的 Docker 引导（它拉一个临时容器跑 `composer create-project` + 安装 Sail）。

- [ ] **Step 1: 确认 ZCard 目录状态**

```bash
ls -la /Users/mac/Project/Php/ZCard
```
Expected: 只有 `.git` 目录（空仓库）。**若已有文件，停下确认**——`laravel.build` 会在当前目录创建。

- [ ] **Step 2: 确认 Docker 在跑**

```bash
docker info > /dev/null 2>&1 && echo "Docker OK" || echo "Docker 未运行，请先启动 Docker Desktop"
```
Expected: `Docker OK`。若未运行，提示用户启动 Docker Desktop 后再继续。

- [ ] **Step 3: 用 Docker 引导创建 Laravel 项目（带 mysql + redis 服务）**

```bash
cd /Users/mac/Project/Php/ZCard && curl -s "https://laravel.build/zcard?with=mysql,redis" | bash
```

说明：`laravel.build` 会创建一个临时 Docker 容器，在 `./zcard` 目录跑 `composer create-project laravel/laravel`，然后 `composer require laravel/sail` 并跑 `php artisan sail:install --with=mysql,redis`。

Expected: 末尾输出 `Zcard is ready! Build something amazing.` 并提示一个随机密码（记下，是数据库 root 密码）。这会在 `/Users/mac/Project/Php/zcard`（小写）生成项目。

> 注意：`laravel.build` 的路径名 `zcard` 会作为目录名。由于我们要在 `ZCard` 里，下一步移动文件。

- [ ] **Step 4: 把生成的项目移到 ZCard 目录**

```bash
cd /Users/mac/Project/Php/ZCard
# 把 zcard 子目录所有文件（含隐藏文件）移到当前目录
shopt -s dotglob nullglob
mv /Users/mac/Project/Php/zcard/* .
shopt -u dotglob nullglob
rmdir /Users/mac/Project/Php/zcard 2>/dev/null || true
```

Expected: `ZCard/` 下出现 `app/, artisan, composer.json, compose.yaml, public/` 等。

- [ ] **Step 5: 验证 Sail 脚本存在且 compose.yaml 含 mysql+redis**

```bash
ls /Users/mac/Project/Php/ZCard/vendor/bin/sail
grep -c "mysql\|redis" /Users/mac/Project/Php/ZCard/compose.yaml
```
Expected: 第一条返回路径；第二条 ≥ 2。

- [ ] **Step 6: 起容器验证 PHP/Laravel 版本**

```bash
cd /Users/mac/Project/Php/ZCard && ./vendor/bin/sail up -d
# 首次会构建镜像，可能 1-3 分钟
./vendor/bin/sail php -v
./vendor/bin/sail artisan --version
```
Expected: PHP ≥ 8.3，Laravel ≥ 13.0。容器起来后访问 http://localhost 应见 Laravel 欢迎页（但本计划后续会替换路由）。

- [ ] **Step 7: 提交初始骨架**

```bash
cd /Users/mac/Project/Php/ZCard
git add -A
git commit -m "chore: scaffold Laravel 13 + Sail (mysql,redis)"
```

---

## Task 2: 配置 .gitignore + 基础 .env

**Files:**
- Modify: `.gitignore`
- Create: `.env.example`（在 Laravel 默认基础上补 ZCard 变量）

- [ ] **Step 1: 编辑 .gitignore，忽略 docs/ 和 storefront 依赖**

在 `.gitignore` 末尾追加（用 Edit 工具，old_string 取末尾已知行）：

追加内容：
```
# ZCard
/docs/
/storefront/node_modules/
/storefront/dist/
/.idea/
/.vscode/
```

> 关键：`docs/` 整个不进 git（用户规则）。

- [ ] **Step 2: 复制 .env 为 .env.example 并补 ZCard 变量**

```bash
cd /Users/mac/Project/Php/ZCard && cp .env .env.example
```

然后用 Edit 在 `.env.example` 末尾追加（这些值在后续 Task 实现，先占位）：
```
# ZCard
APP_ADMIN_EMAIL=admin@zcard.local
SANCTUM_STATEFUL_DOMAINS=localhost:5173,localhost
SESSION_DOMAIN=localhost
CARD_ENCRYPTION_KEY=
```

> `.env`（实际环境）保留 `APP_KEY`、真实 DB 密码，**不提交**（Laravel 默认已忽略）。`.env.example` 是模板，提交。

- [ ] **Step 3: 提交**

```bash
cd /Users/mac/Project/Php/ZCard && git add .gitignore .env.example && git commit -m "chore: gitignore docs/ and storefront; add .env.example"
```

---

## Task 3: 安装核心依赖（Filament v5 + spatie + filament-shield）

**Files:**
- Modify: `composer.json`（依赖）

- [ ] **Step 1: 安装 Filament v5**

```bash
cd /Users/mac/Project/Php/ZCard && ./vendor/bin/sail composer require "filament/filament:^5.0"
```
Expected: 安装成功，无冲突。

- [ ] **Step 2: 安装 spatie/laravel-permission + filament-shield**

```bash
./vendor/bin/sail composer require "spatie/laravel-permission:^8.0" "bezhansalleh/filament-shield:^5.0"
```
Expected: 安装成功。

- [ ] **Step 3: 发布 spatie 配置与迁移**

```bash
./vendor/bin/sail artisan vendor:publish --provider="Spatie\Permission\PermissionServiceProvider"
```
Expected: `config/permission.php` 和 `database/migrations/*_create_permission_tables.php` 生成。

- [ ] **Step 4: 提交**

```bash
git add -A && git commit -m "chore: install filament v5, spatie/permission, filament-shield"
```

---

## Task 4: 配置 Filament Admin Panel + 主题（设计图配色）

**Files:**
- Create: `app/Providers/Filament/AdminPanelProvider.php`
- Modify: `bootstrap/providers.php`（注册 panel provider）
- Modify: `app/Models/User.php`（实现 FilamentUser，限制后台访问）
- Create: `resources/css/filament/admin/theme.css`（自定义主题入口占位）

- [ ] **Step 1: 生成 Filament panel**

```bash
./vendor/bin/sail artisan filament:install --panels
```
按提示输入 panel 名：`admin`。
Expected: 生成 `app/Providers/Filament/AdminPanelProvider.php`，并在 `bootstrap/providers.php` 注册。

- [ ] **Step 2: 写 AdminPanelProvider（主题 + 路径 + 浅色 + 中间件占位）**

完全替换 `app/Providers/Filament/AdminPanelProvider.php` 内容为：

```php
<?php

namespace App\Providers\Filament;

use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Support\Facade\FilamentView;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->login()
            ->colors([
                // 设计图配色（spec §5）
                'primary' => '#2563EB',
                'success' => '#10B981',
                'warning' => '#F59E0B',
                'danger' => '#EF4444',
            ])
            ->brand('ZCard')
            ->favicon(asset('/favicon.ico'))
            // ForcePasswordChange 中间件在 Task 9 加入
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                VerifyCsrfToken::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                // ForcePasswordChange::class,  // Task 9 启用
            ]);
    }
}
```

- [ ] **Step 3: 改 User 模型实现 FilamentUser（顾客 user 角色禁止进后台）**

修改 `app/Models/User.php`，确保：
- `implements` 加 `FilamentUser`（`use Filament\Models\Contracts\FilamentUser;`）
- 加方法：
```php
public function canAccessPanel(Panel $panel): bool
{
    // 顾客(user 角色)不能进 /admin；super_admin/merchant 可进
    return $this->hasRole(['super_admin', 'merchant']);
}
```
（User 模型的其他字段改动在 Task 6 统一处理，这里只加 FilamentUser 契约。）

- [ ] **Step 4: 创建自定义主题 CSS 占位文件**

创建 `resources/css/filament/admin/theme.css`，内容：
```css
/*
 * ZCard Filament 自定义主题层。
 * 设计图 token（spec §5）：浅色、主色 #2563EB、圆角 8px、卡片背景 #F9FAFB。
 * Phase 0 仅占位；后续微调（圆角、阴影、卡片底色）写在这里，不动 Filament 核心。
 */
```

- [ ] **Step 5: 起容器验证后台可访问（未登录应跳登录页）**

```bash
./vendor/bin/sail up -d
curl -sI http://localhost/admin | head -1
```
Expected: HTTP 302（跳转登录）或 200（登录页）。若 500，看 `./vendor/bin/sail artisan about` 排错。

- [ ] **Step 6: 提交**

```bash
git add -A && git commit -m "feat(filament): configure admin panel with design tokens"
```

---

## Task 5: 创建 ZCard 配置文件（功能开关占位）

**Files:**
- Create: `config/zcard.php`

- [ ] **Step 1: 创建 config/zcard.php**

```php
<?php

// ZCard 应用配置。功能开关（Open Core）预留，Phase 0 不消费，后续 Phase 3+ 启用。

return [

    // 功能开关：商业版功能默认 false（开源版）。
    'features' => [
        'multi_merchant' => env('ZCARD_MULTI_MERCHANT', false), // 多商户/多店（Phase 3）
        'distribution' => env('ZCARD_DISTRIBUTION', false),     // 三级分销（Phase 3）
        'sub_site' => env('ZCARD_SUB_SITE', false),             // 分站（Phase 3）
    ],

    // 卡密加密密钥（应用层 AES，spec §6.1 决策3）。32 字节 base64。
    'card_encryption_key' => env('CARD_ENCRYPTION_KEY'),

    // 卡密默认发放模式：status=保留/used，delete=物理删除（spec §6.1 决策12）
    'card_default_delivery_mode' => env('ZCARD_CARD_DELIVERY_MODE', 'status'),
];
```

- [ ] **Step 2: 提交**

```bash
git add config/zcard.php && git commit -m "feat(config): add zcard.php feature flags and card cipher config"
```

---

## Task 6: 核心模型与迁移（身份：users/merchants/merchant_members）

**Files:**
- Modify: `database/migrations/0001_01_01_000000_create_users_table.php`（Laravel 默认，加字段）
- Modify: `app/Models/User.php`
- Create: `database/migrations/2026_07_28_000010_create_merchants_table.php`
- Create: `database/migrations/2026_07_28_000020_create_merchant_members_table.php`
- Create: `app/Models/Merchant.php`, `app/Models/MerchantMember.php`

- [ ] **Step 1: 修改默认 users 迁移（加 ZCard 字段）**

打开 `database/migrations/0001_01_01_000000_create_users_table.php`，在 `create` 闭包内，`(string) 'name'` 那行之后/之间调整为：

把 `$table->string('name');` 改为：
```php
$table->string('username', 50)->unique();
$table->string('name', 100)->nullable();
```
并在 `password` 之后、`rememberToken` 之前加：
```php
$table->unsignedTinyInteger('status')->default(1)->comment('1=正常 0=禁用');
$table->bigInteger('balance')->default(0)->comment('余额，单位分');
$table->timestamp('password_changed_at')->nullable()->comment('首次登录强制改密用；null=待改');
$table->timestamp('last_login_at')->nullable();
```
末尾把 `$table->timestamps();` 改为 `$table->softDeletes();` 之后再补 `timestamps`：
```php
$table->timestamps();
$table->softDeletes();
```

- [ ] **Step 2: 改 User 模型（含 Task 4 已加的 FilamentUser）**

完整重写 `app/Models/User.php`：
```php
<?php

namespace App\Models;

use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable implements FilamentUser
{
    use HasApiTokens, HasFactory, HasRoles, Notifiable, SoftDeletes;

    protected $fillable = [
        'username', 'name', 'email', 'password', 'status',
        'balance', 'password_changed_at', 'last_login_at',
    ];

    protected $hidden = [
        'password', 'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'password_changed_at' => 'datetime',
            'last_login_at' => 'datetime',
        ];
    }

    public function canAccessPanel(Panel $panel): bool
    {
        return $this->hasRole(['super_admin', 'merchant']);
    }
}
```

- [ ] **Step 3: 创建 merchants 迁移**

`database/migrations/2026_07_28_000010_create_merchants_table.php`：
```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('merchants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('name', 100);
            $table->string('slug', 100)->unique();
            $table->unsignedTinyInteger('status')->default(1)->comment('1=正常 0=禁用');
            $table->decimal('commission_rate', 5, 4)->default(0)->comment('佣金率 0.0000~9.9999');
            $table->json('settings')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('merchants');
    }
};
```

- [ ] **Step 4: 创建 merchant_members 迁移**

`database/migrations/2026_07_28_000020_create_merchant_members_table.php`：
```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('merchant_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('merchant_id')->constrained('merchants')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('role', 50)->default('staff')->comment('owner/staff 等');
            $table->timestamps();
            $table->unique(['merchant_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('merchant_members');
    }
};
```

- [ ] **Step 5: 创建 Merchant、MerchantMember 模型**

`app/Models/Merchant.php`：
```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Merchant extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'user_id', 'name', 'slug', 'status', 'commission_rate', 'settings',
    ];

    protected function casts(): array
    {
        return ['settings' => 'array'];
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function members(): HasMany
    {
        return $this->hasMany(MerchantMember::class);
    }
}
```

`app/Models/MerchantMember.php`：
```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MerchantMember extends Model
{
    protected $fillable = ['merchant_id', 'user_id', 'role'];

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
```

- [ ] **Step 6: 跑迁移验证（含 spatie 表）**

```bash
./vendor/bin/sail artisan migrate:fresh
```
Expected: 输出 migrated 列表，含 `users`, `merchants`, `merchant_members`, `permissions`, `roles` 等无报错。

- [ ] **Step 7: 提交**

```bash
git add -A && git commit -m "feat(db): users/merchants/merchant_members models and migrations"
```

---

## Task 7: 卡密加密工具 CardCipher（应用层加密 + hash）

**Files:**
- Create: `app/Support/CardCipher.php`

这一步先做工具类，Task 8 的 cards 迁移和模型会依赖它。

- [ ] **Step 1: 创建 CardCipher**

`app/Support/CardCipher.php`：
```php
<?php

namespace App\Support;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Encryption\Encrypter;

/**
 * 卡密加解密工具（spec §6.1 决策3）。
 *
 * 设计要点：
 * - 加密用独立密钥 CARD_ENCRYPTION_KEY（与 APP_KEY 解耦），AES-256-CBC。
 * - 去重靠 sha256 明文 hash 存 content_hash 列（可索引），不靠密文。
 * - 不用 Laravel `encrypted` cast（其随机 IV 使密文不可比对）。
 *
 * Phase 0 提供基础加解密；批量加密（导入）在 Phase 1 实现时复用 encrypt()。
 */
class CardCipher
{
    private static function encrypter(): Encrypter
    {
        $key = config('zcard.card_encryption_key');
        if (empty($key)) {
            throw new \RuntimeException('CARD_ENCRYPTION_KEY 未配置，请运行 zcard:install 或在 .env 设置。');
        }
        return new Encrypter($key, 'AES-256-CBC');
    }

    /** 加密单条明文卡密 → 密文 */
    public static function encrypt(string $plain): string
    {
        return self::encrypter()->encryptString($plain);
    }

    /** 解密单条密文 → 明文（发货/展示时用） */
    public static function decrypt(string $cipher): string
    {
        return self::encrypter()->decryptString($cipher);
    }

    /** 明文 → sha256 hash（用于去重索引 content_hash） */
    public static function hash(string $plain): string
    {
        return hash('sha256', $plain);
    }

    /** 加密并算 hash，返回 [content, content_hash]，供插入用 */
    public static function encryptWithHash(string $plain): array
    {
        return [
            'content' => self::encrypt($plain),
            'content_hash' => self::hash($plain),
        ];
    }
}
```

- [ ] **Step 2: 提交**

```bash
git add app/Support/CardCipher.php && git commit -m "feat(card): CardCipher for app-layer encryption + sha256 dedup"
```

---

## Task 8: 商品/卡密/订单/配置 迁移与模型

**Files:**
- Create: `database/migrations/2026_07_28_000030_create_categories_table.php`
- Create: `database/migrations/2026_07_28_000040_create_products_table.php`
- Create: `database/migrations/2026_07_28_000050_create_card_imports_table.php`
- Create: `database/migrations/2026_07_28_000060_create_cards_table.php`
- Create: `database/migrations/2026_07_28_000070_create_orders_table.php`
- Create: `database/migrations/2026_07_28_000080_create_order_items_table.php`
- Create: `database/migrations/2026_07_28_000090_create_payments_table.php`
- Create: `database/migrations/2026_07_28_000100_create_order_deliveries_table.php`
- Create: `database/migrations/2026_07_28_000110_create_settings_table.php`
- Create: 对应 9 个模型：Category, Product, CardImport, Card, Order, OrderItem, Payment, OrderDelivery, Setting

> 字段严格对应 spec §6.2。金额一律 `bigInteger`（单位分）。cards 用 `UNIQUE(product_id, content_hash)` + `INDEX(product_id, status)`。

- [ ] **Step 1: categories 迁移**

```php
<?php
// database/migrations/2026_07_28_000030_create_categories_table.php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('parent_id')->nullable()->constrained('categories')->nullOnDelete();
            $table->foreignId('merchant_id')->constrained('merchants')->cascadeOnDelete();
            $table->string('name', 100);
            $table->string('slug', 100);
            $table->unsignedInteger('sort')->default(0);
            $table->unsignedTinyInteger('status')->default(1);
            $table->timestamps();
            $table->unique(['merchant_id', 'slug']); // 多商户下唯一(spec §6.1 决策10)
        });
    }
    public function down(): void { Schema::dropIfExists('categories'); }
};
```

- [ ] **Step 2: products 迁移**

```php
<?php
// database/migrations/2026_07_28_000040_create_products_table.php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('merchant_id')->constrained('merchants')->cascadeOnDelete();
            $table->foreignId('category_id')->nullable()->constrained('categories')->nullOnDelete();
            $table->string('name', 150);
            $table->string('slug', 150);
            $table->longText('description')->nullable();
            $table->bigInteger('price')->default(0)->comment('单位分');
            $table->json('member_price')->nullable()->comment('按会员等级 {level: price}');
            $table->string('cover')->nullable();
            $table->json('images')->nullable();
            $table->string('stock_type', 20)->default('card')->comment('card/url/code');
            $table->boolean('stock_visible')->default(true)->comment('是否显示库存数');
            $table->json('control_config')->nullable()->comment('自定义控件配置');
            $table->string('delivery_mode', 10)->default('status')->comment('status=保留 delete=删除');
            $table->unsignedInteger('sort')->default(0);
            $table->unsignedTinyInteger('status')->default(1);
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['merchant_id', 'slug']);
        });
    }
    public function down(): void { Schema::dropIfExists('products'); }
};
```

- [ ] **Step 3: card_imports 迁移**

```php
<?php
// database/migrations/2026_07_28_000050_create_card_imports_table.php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('card_imports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->foreignId('operator_id')->constrained('users')->cascadeOnDelete();
            $table->string('source', 255)->nullable()->comment('文件名/来源');
            $table->unsignedInteger('total')->default(0)->comment('文件总行数');
            $table->unsignedInteger('success_count')->default(0);
            $table->unsignedInteger('failed_count')->default(0);
            $table->string('status', 20)->default('running')->comment('running/completed/failed');
            $table->json('error_log')->nullable()->comment('失败明细');
            $table->timestamps();
        });
    }
    public function down(): void { Schema::dropIfExists('card_imports'); }
};
```

- [ ] **Step 4: cards 迁移（核心：去重 + 库存索引）**

```php
<?php
// database/migrations/2026_07_28_000060_create_cards_table.php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('cards', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->foreignId('import_id')->nullable()->constrained('card_imports')->nullOnDelete();
            $table->text('content')->comment('应用层加密密文');
            $table->string('content_hash', 64)->comment('sha256 明文，去重索引用');
            $table->string('status', 10)->default('unused')->comment('unused/locked/used/disabled');
            $table->foreignId('order_id')->nullable()->constrained('orders')->nullOnDelete();
            $table->timestamp('locked_at')->nullable()->comment('锁定发货超时释放用');
            $table->timestamp('used_at')->nullable();
            $table->timestamps();

            // 产品内唯一(spec §6.1 决策3)：同一产品内卡密不重复，跨产品允许相同
            $table->unique(['product_id', 'content_hash']);
            $table->index(['product_id', 'status']); // 库存查询/发货热路径
            $table->index('order_id');
            $table->index('import_id');
        });
    }
    public function down(): void { Schema::dropIfExists('cards'); }
};
```

> 注意：`cards.order_id` 引用 orders 表，但 orders 迁移在后面（000070）。MySQL 允许前向引用，但 `constrained()` 在表不存在时会报错。**调整顺序**：把 cards 迁移的 `order_id` 改为 `foreignId('order_id')->nullable()` 不加 constrained，在 orders 迁移后单独加外键；或**简单方案**：调整 cards 中 order_id 不加外键约束，仅加索引。这里采用简单方案，避免顺序耦合：

cards 迁移里 `order_id` 行改为：
```php
$table->unsignedBigInteger('order_id')->nullable()->index();
```
（不加外键，spec 也只要求 index(order_id)）

- [ ] **Step 5: orders 迁移**

```php
<?php
// database/migrations/2026_07_28_000070_create_orders_table.php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->string('order_no', 40)->unique();
            $table->foreignId('merchant_id')->constrained('merchants')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete()->comment('游客=null');
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->unsignedInteger('quantity')->default(1);
            $table->bigInteger('amount')->default(0)->comment('单位分');
            $table->string('status', 20)->default('pending')->comment('pending/paid/closed/refunded');
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->string('contact', 150)->nullable()->comment('联系方式');
            $table->json('extra')->nullable();
            $table->timestamps();
        });
    }
    public function down(): void { Schema::dropIfExists('orders'); }
};
```

- [ ] **Step 6: order_items 迁移（无 card_ids）**

```php
<?php
// database/migrations/2026_07_28_000080_create_order_items_table.php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->bigInteger('amount')->default(0)->comment('单位分');
            $table->timestamps();
            // 无 card_ids：卡密经 cards.order_id 反查（spec §6.1 决策9）
        });
    }
    public function down(): void { Schema::dropIfExists('order_items'); }
};
```

- [ ] **Step 7: payments 迁移**

```php
<?php
// database/migrations/2026_07_28_000090_create_payments_table.php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            $table->string('channel', 50);
            $table->string('channel_order_no', 80)->nullable();
            $table->bigInteger('amount')->default(0)->comment('单位分');
            $table->string('status', 20)->default('pending')->comment('pending/success/failed');
            $table->timestamp('paid_at')->nullable();
            $table->json('raw')->nullable()->comment('回调原文');
            $table->timestamps();
        });
    }
    public function down(): void { Schema::dropIfExists('payments'); }
};
```

- [ ] **Step 8: order_deliveries 迁移（发货快照）**

```php
<?php
// database/migrations/2026_07_28_000100_create_order_deliveries_table.php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('order_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->text('card_content')->comment('明文卡密（客户最终看到的）');
            $table->string('delivered_mode', 10)->comment('status/delete 实际使用的');
            $table->timestamp('delivered_at');
            $table->timestamps();
            $table->index('order_id');
            $table->index('product_id');
        });
    }
    public function down(): void { Schema::dropIfExists('order_deliveries'); }
};
```

- [ ] **Step 9: settings 迁移**

```php
<?php
// database/migrations/2026_07_28_000110_create_settings_table.php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('settings', function (Blueprint $table) {
            $table->id();
            $table->string('key', 100)->unique();
            $table->json('value')->nullable();
            $table->string('group', 50)->nullable();
            $table->timestamps();
        });
    }
    public function down(): void { Schema::dropIfExists('settings'); }
};
```

- [ ] **Step 10: 创建 9 个模型**

`app/Models/Category.php`：
```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Category extends Model
{
    protected $fillable = ['parent_id', 'merchant_id', 'name', 'slug', 'sort', 'status'];

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }
}
```

`app/Models/Product.php`：
```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Product extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'merchant_id', 'category_id', 'name', 'slug', 'description', 'price',
        'member_price', 'cover', 'images', 'stock_type', 'stock_visible',
        'control_config', 'delivery_mode', 'sort', 'status',
    ];

    protected function casts(): array
    {
        return [
            'member_price' => 'array',
            'images' => 'array',
            'control_config' => 'array',
            'stock_visible' => 'boolean',
        ];
    }

    public function merchant(): BelongsTo { return $this->belongsTo(Merchant::class); }
    public function cards(): HasMany { return $this->hasMany(Card::class); }
}
```

`app/Models/CardImport.php`：
```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CardImport extends Model
{
    protected $fillable = [
        'product_id', 'operator_id', 'source', 'total',
        'success_count', 'failed_count', 'status', 'error_log',
    ];

    protected function casts(): array
    {
        return ['error_log' => 'array'];
    }

    public function product(): BelongsTo { return $this->belongsTo(Product::class); }
    public function cards(): HasMany { return $this->hasMany(Card::class); }
}
```

`app/Models/Card.php`：
```php
<?php

namespace App\Models;

use App\Support\CardCipher;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Card extends Model
{
    const STATUS_UNUSED = 'unused';
    const STATUS_LOCKED = 'locked';
    const STATUS_USED = 'used';
    const STATUS_DISABLED = 'disabled';

    protected $fillable = [
        'product_id', 'import_id', 'content', 'content_hash',
        'status', 'order_id', 'locked_at', 'used_at',
    ];

    protected function casts(): array
    {
        return [
            'locked_at' => 'datetime',
            'used_at' => 'datetime',
        ];
    }

    public function product(): BelongsTo { return $this->belongsTo(Product::class); }
    public function import(): BelongsTo { return $this->belongsTo(CardImport::class); }

    /** 取明文卡密（解密） */
    public function plainContent(): string
    {
        return CardCipher::decrypt($this->content);
    }
}
```

`app/Models/Order.php`：
```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Order extends Model
{
    protected $fillable = [
        'order_no', 'merchant_id', 'user_id', 'product_id', 'quantity',
        'amount', 'status', 'paid_at', 'closed_at', 'contact', 'extra',
    ];

    protected function casts(): array
    {
        return ['paid_at' => 'datetime', 'closed_at' => 'datetime', 'extra' => 'array'];
    }

    public function product(): BelongsTo { return $this->belongsTo(Product::class); }
    public function payments(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(Payment::class);
    }
}
```

`app/Models/OrderItem.php`：
```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderItem extends Model
{
    protected $fillable = ['order_id', 'product_id', 'amount'];

    public function order(): BelongsTo { return $this->belongsTo(Order::class); }
}
```

`app/Models/Payment.php`：
```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Payment extends Model
{
    protected $fillable = [
        'order_id', 'channel', 'channel_order_no', 'amount', 'status', 'paid_at', 'raw',
    ];

    protected function casts(): array
    {
        return ['paid_at' => 'datetime', 'raw' => 'array'];
    }

    public function order(): BelongsTo { return $this->belongsTo(Order::class); }
}
```

`app/Models/OrderDelivery.php`：
```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderDelivery extends Model
{
    protected $fillable = ['order_id', 'product_id', 'card_content', 'delivered_mode', 'delivered_at'];

    protected function casts(): array
    {
        return ['delivered_at' => 'datetime'];
    }

    public function order(): BelongsTo { return $this->belongsTo(Order::class); }
}
```

`app/Models/Setting.php`：
```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Setting extends Model
{
    protected $fillable = ['key', 'value', 'group'];

    protected function casts(): array
    {
        return ['value' => 'array'];
    }
}
```

- [ ] **Step 11: 跑 migrate:fresh 验证全部 12 张业务表 + spatie 表**

```bash
./vendor/bin/sail artisan migrate:fresh
./vendor/bin/sail artisan tinker --execute="echo implode(',', \Schema::getTableListing());"
```
Expected: 输出含 `users, merchants, merchant_members, categories, products, card_imports, cards, orders, order_items, payments, order_deliveries, settings, permissions, roles, ...`，共 12 张业务 + 5 张 spatie。

- [ ] **Step 12: 提交**

```bash
git add -A && git commit -m "feat(db): products/cards/card_imports/orders/deliveries/settings schemas + models"
```

---

## Task 9: RBAC 初始化 + zcard:install 命令 + 强制改密中间件

**Files:**
- Modify: `app/Providers/Filament/AdminPanelProvider.php`（启用 ForcePasswordChange）
- Create: `app/Http/Middleware/ForcePasswordChange.php`
- Create: `app/Console/Commands/InstallCommand.php`
- Modify: `database/seeders/DatabaseSeeder.php`（不建账号）

- [ ] **Step 1: 创建 ForcePasswordChange 中间件**

`app/Http/Middleware/ForcePasswordChange.php`：
```php
<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * 首次登录强制改密（spec §7.3）。
 * super_admin/merchant 角色登录后若 password_changed_at 为 null → 跳改密页。
 * Filament 登录后访问非改密页时拦截。
 */
class ForcePasswordChange
{
    public function handle(Request $request, Closure $next): Response
    {
        /** @var User $user */
        $user = $request->user();
        if (! $user) {
            return $next($request);
        }

        // 只约束后台角色
        if (! $user->hasRole(['super_admin', 'merchant'])) {
            return $next($request);
        }

        // 已改密，放行
        if ($user->password_changed_at !== null) {
            return $next($request);
        }

        // 当前已在改密相关路由，放行避免死循环
        if ($request->routeIs('filament.admin.pages.profile') ||
            $request->is('*/profile*') ||
            $request->is('logout*')) {
            return $next($request);
        }

        // 跳到 Filament 个人资料页（含改密）。Phase 0 用 profile 页承载改密。
        return redirect()->route('filament.admin.pages.profile');
    }
}
```

- [ ] **Step 2: 在 AdminPanelProvider 启用中间件**

修改 `app/Providers/Filament/AdminPanelProvider.php`，把 `authMiddleware` 数组里 `// ForcePasswordChange::class, // Task 9 启用` 改为：
```php
use App\Http\Middleware\ForcePasswordChange;
...
->authMiddleware([
    \Filament\Http\Middleware\Authenticate::class,
    ForcePasswordChange::class,
]),
```

- [ ] **Step 3: 创建 InstallCommand**

`app/Console/Commands/InstallCommand.php`：
```php
<?php

namespace App\Console\Commands;

use App\Models\Merchant;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class InstallCommand extends Command
{
    protected $signature = 'zcard:install {--email=admin@zcard.local : 超级管理员邮箱}';
    protected $description = 'ZCard 系统初始化：迁移、角色权限、默认商户、超管账号（随机8位密码）';

    public function handle(): int
    {
        $this->info('ZCard 系统初始化');

        // 1. APP_KEY
        if (empty(config('app.key')) || str_starts_with(config('app.key'), 'base64:')) {
            $this->call('key:generate');
        }
        $this->info(' ✔ 生成应用密钥');

        // 2. 卡密加密密钥
        if (empty(config('zcard.card_encryption_key'))) {
            $key = base64_encode(random_bytes(32));
            $this->writeEnv('CARD_ENCRYPTION_KEY', $key);
            $this->info(' ✔ 生成卡密加密密钥');
        }

        // 3. 迁移
        $this->call('migrate', ['--force' => true]);
        $this->info(' ✔ 迁移数据库');

        // 4. 角色与权限（幂等）
        $roles = ['super_admin', 'merchant', 'user'];
        foreach ($roles as $r) {
            Role::firstOrCreate(['name' => $r]);
        }
        // filament-shield 生成的权限同步给 super_admin
        $this->call('shield:super-admin');
        $this->info(' ✔ 创建角色与权限');

        // 5. 默认商户（merchant_id=1）
        $merchant = Merchant::firstOrCreate(
            ['slug' => 'default'],
            ['user_id' => 0, 'name' => '默认商户', 'status' => 1, 'commission_rate' => 0]
        );
        $this->info(' ✔ 创建默认商户（slug=default）');

        // 6. 超管账号（幂等）
        $email = $this->option('email');
        $exists = User::where('email', $email)->exists();
        if ($exists) {
            $this->warn("   邮箱 {$email} 已存在账号，跳过创建。");
        } else {
            $password = Str::random(8); // 8 位随机密码(spec §7.2 决策)
            $user = User::create([
                'username' => 'admin',
                'name' => 'Super Admin',
                'email' => $email,
                'password' => $password,
                'status' => 1,
                'password_changed_at' => null, // 强制首次改密
            ]);
            $user->assignRole('super_admin');
            $merchant->update(['user_id' => $user->id]);

            $this->info(' ✔ 创建超级管理员账号');
            $this->line('');
            $this->line("   邮箱：  {$email}");
            $this->line('   初始密码（随机生成，请妥善保存）：');
            $this->line("   ┌──────────────────────────┐");
            $this->line("   │  {$password}              │");
            $this->line("   └──────────────────────────┘");
            $this->warn('   ⚠ 首次登录后请立即在「个人设置」修改密码');
        }

        $this->info('');
        $this->info(' ✔ 安装完成。访问 /admin 登录。');
        return self::SUCCESS;
    }

    /** 写入 .env（简单实现） */
    private function writeEnv(string $key, string $value): void
    {
        $path = base_path('.env');
        if (! file_exists($path)) {
            return;
        }
        $content = file_get_contents($path);
        if (str_contains($content, "{$key}=")) {
            $content = preg_replace('/^' . preg_quote($key, '/') . '=.*/m', "{$key}={$value}", $content);
        } else {
            $content .= "\n{$key}={$value}\n";
        }
        file_put_contents($path, $content);
    }
}
```

- [ ] **Step 4: 改 DatabaseSeeder（只做演示数据占位，不建账号）**

完全重写 `database/seeders/DatabaseSeeder.php`：
```php
<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // 账号由 `php artisan zcard:install` 创建，此处不建账号（spec §7.2）。
        // 演示数据（商品/卡密样例）留给后续 Phase；生产环境不应跑 seed。
        // $this->call([]);
    }
}
```

- [ ] **Step 5: 跑 install 验证**

```bash
./vendor/bin/sail artisan migrate:fresh
./vendor/bin/sail artisan zcard:install
```
Expected: 输出步骤 ✔，打印随机 8 位密码。再跑一次应提示"已存在，跳过"（幂等）。

- [ ] **Step 6: 验证登录后强制改密**

用浏览器访问 `http://localhost/admin`，用生成的邮箱密码登录 → 应被跳转到个人资料页（因 password_changed_at=null）。在资料页改密后应能进入后台。

- [ ] **Step 7: 提交**

```bash
git add -A && git commit -m "feat(rbac): zcard:install command + force password change middleware"
```

---

## Task 10: Filament Resources（User + Merchant）+ shield 生成权限

**Files:**
- Create: `app/Filament/Resources/UserResource.php`（含 Pages）
- Create: `app/Filament/Resources/MerchantResource.php`（含 Pages）

- [ ] **Step 1: 生成 UserResource**

```bash
./vendor/bin/sail artisan filament:resource User
```
Expected: 生成 `app/Filament/Resources/UserResource.php` + `app/Filament/Resources/UserResource/Pages/`。

- [ ] **Step 2: 编辑 UserResource 表单/表格字段**

打开 `app/Filament/Resources/UserResource.php`，在 `form()` 里配置 schema：
```php
use Filament\Forms;
use Filament\Tables;
...
public static function form(\Filament\Forms\Form $form): \Filament\Forms\Form
{
    return $form->schema([
        Forms\Components\TextInput::make('username')->required()->maxLength(50),
        Forms\Components\TextInput::make('name')->maxLength(100),
        Forms\Components\TextInput::make('email')->email()->required()->maxLength(255),
        Forms\Components\Select::make('status')->options([1 => '正常', 0 => '禁用'])->default(1),
        Forms\Components\TextInput::make('balance')->numeric()->default(0)->label('余额(分)'),
        Forms\Components\Select::make('roles')
            ->relationship('roles', 'name')
            ->multiple()
            ->preload()
            ->label('角色'),
    ]);
}

public static function table(\Filament\Tables\Table $table): \Filament\Tables\Table
{
    return $table->columns([
        Tables\Columns\TextColumn::make('id')->sortable(),
        Tables\Columns\TextColumn::make('username')->searchable(),
        Tables\Columns\TextColumn::make('email')->searchable(),
        Tables\Columns\TextColumn::make('balance')->label('余额(分)'),
        Tables\Columns\TextColumn::make('roles.name')->badge()->label('角色'),
        Tables\Columns\TextColumn::make('status')->badge(),
        Tables\Columns\TextColumn::make('created_at')->dateTime()->sortable(),
    ])->filters([
        //
    ])->actions([
        Tables\Actions\EditAction::make(),
    ])->bulkActions([
        Tables\Actions\BulkActionGroup::make([
            Tables\Actions\DeleteBulkAction::make(),
        ]),
    ]);
}
```

- [ ] **Step 3: 生成 MerchantResource 并配置字段**

```bash
./vendor/bin/sail artisan filament:resource Merchant
```
打开 `app/Filament/Resources/MerchantResource.php`，配置 `form()` 与 `table()`：

```php
use Filament\Forms;
use Filament\Tables;
...
public static function form(\Filament\Forms\Form $form): \Filament\Forms\Form
{
    return $form->schema([
        Forms\Components\TextInput::make('name')->required()->maxLength(100)->label('商户名称'),
        Forms\Components\TextInput::make('slug')->required()->maxLength(100)->label('Slug'),
        Forms\Components\Select::make('status')->options([1 => '正常', 0 => '禁用'])->default(1)->label('状态'),
        Forms\Components\TextInput::make('commission_rate')
            ->numeric()->default(0)->step(0.0001)->label('佣金率(0.0000~9.9999)'),
    ]);
}

public static function table(\Filament\Tables\Table $table): \Filament\Tables\Table
{
    return $table->columns([
        Tables\Columns\TextColumn::make('id')->sortable(),
        Tables\Columns\TextColumn::make('name')->searchable()->label('商户名称'),
        Tables\Columns\TextColumn::make('slug')->label('Slug'),
        Tables\Columns\TextColumn::make('commission_rate')->label('佣金率'),
        Tables\Columns\TextColumn::make('status')->badge()->label('状态'),
        Tables\Columns\TextColumn::make('created_at')->dateTime()->sortable(),
    ])->actions([
        Tables\Actions\EditAction::make(),
    ])->bulkActions([
        Tables\Actions\BulkActionGroup::make([
            Tables\Actions\DeleteBulkAction::make(),
        ]),
    ]);
}
```

- [ ] **Step 4: 用 shield 生成权限 + Policy**

```bash
./vendor/bin/sail artisan shield:generate --resource=UserResource
./vendor/bin/sail artisan shield:generate --resource=MerchantResource
```
Expected: 生成 `app/Policies/UserPolicy.php`, `app/Policies/MerchantPolicy.php`，并创建对应权限记录。

- [ ] **Step 5: 验证后台显示两个 Resource + 权限**

```bash
./vendor/bin/sail artisan zcard:install   # 确保 super_admin 有新权限
```
浏览器访问 `http://localhost/admin` → 左侧应见「User management」「Merchant management」（或对应中文）。super_admin 可见/可操作。

- [ ] **Step 6: 提交**

```bash
git add -A && git commit -m "feat(filament): User and Merchant resources with shield permissions"
```

---

## Task 11: 前台 API 健康检查接口

**Files:**
- Modify: `routes/api.php`
- Create: `app/Http/Controllers/Api/HealthController.php`
- Modify: `bootstrap/app.php`（确保 api 路由启用 + 配置 sanctum/cors）

- [ ] **Step 1: 安装 sanctum（API token 用）**

```bash
./vendor/bin/sail composer require laravel/sanctum
./vendor/bin/sail artisan vendor:publish --provider="Laravel\Sanctum\SanctumServiceProvider"
```

- [ ] **Step 2: 创建 HealthController**

`app/Http/Controllers/Api/HealthController.php`：
```php
<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

class HealthController extends Controller
{
    public function __invoke(): JsonResponse
    {
        return response()->json([
            'status' => 'ok',
            'service' => 'ZCard API',
            'time' => now()->toIso8601String(),
        ]);
    }
}
```

- [ ] **Step 3: 注册路由**

`routes/api.php`：
```php
<?php

use App\Http\Controllers\Api\HealthController;
use Illuminate\Support\Facades\Route;

Route::get('/health', HealthController::class)->name('api.health');
```

- [ ] **Step 4: 验证**

```bash
curl -s http://localhost/api/health
```
Expected: `{"status":"ok","service":"ZCard API","time":"..."}` JSON。

- [ ] **Step 5: 提交**

```bash
git add -A && git commit -m "feat(api): health check endpoint"
```

---

## Task 12: 插件目录骨架占位（example-plugin）

**Files:**
- Create: `plugins/example-plugin/plugin.json`
- Create: `plugins/example-plugin/src/ServiceProvider.php`
- Create: `plugins/example-plugin/README.md`

> spec §3.2：Phase 0 仅放占位，**不**加载运行。Phase 2 实现 Hook 总线。

- [ ] **Step 1: 创建 plugin.json（清单规范占位）**

`plugins/example-plugin/plugin.json`：
```json
{
    "id": "example-plugin",
    "name": "示例插件",
    "version": "0.1.0",
    "description": "ZCard 示例插件骨架。Phase 0 仅占位，Phase 2 插件系统生效后才会被加载。",
    "author": "ZCard",
    "homepage": "",
    "min_app_version": "1.0.0",
    "hooks": {
        "order.paid": "App\\Plugins\\ExamplePlugin\\Hooks::onOrderPaid"
    },
    "permissions": [
        {"name": "example.view", "display": "查看示例插件"}
    ],
    "config": []
}
```

- [ ] **Step 2: 创建 ServiceProvider 占位**

`plugins/example-plugin/src/ServiceProvider.php`：
```php
<?php

namespace App\Plugins\ExamplePlugin;

use Illuminate\Support\ServiceProvider as BaseServiceProvider;

/**
 * 示例插件入口（占位）。
 * Phase 0：不会被主程序加载。Phase 2：由插件系统按 plugin.json 的 hooks 注册监听。
 */
class ServiceProvider extends BaseServiceProvider
{
    public function register(): void
    {
        // Phase 2 实现：注册路由/视图/Hook
    }

    public function boot(): void
    {
        // Phase 2 实现：监听事件
    }
}
```

- [ ] **Step 3: 创建 README**

`plugins/example-plugin/README.md`：
```markdown
# 示例插件（example-plugin）

> ⚠ **Phase 0 占位**：本插件当前不会被 ZCard 主程序加载。
> 插件系统的 Hook 总线、安装/启停生命周期在 **Phase 2** 实现（见 spec §3.2）。

本目录用于演示未来插件的标准结构：

\`\`\`
plugins/example-plugin/
├── plugin.json          # 清单：名称/版本/hooks/权限/配置
├── src/ServiceProvider.php  # 插件入口
└── README.md
\`\`\`

Phase 2 文档完善后，第三方可照此结构编写并在线安装启停插件。
```

- [ ] **Step 4: 提交**

```bash
git add plugins/ && git commit -m "feat(plugins): add example-plugin skeleton placeholder (Phase 2)"
```

---

## Task 13: 前台 Vue3 工程（storefront）

**Files:**
- Create: `storefront/package.json`, `storefront/vite.config.ts`, `storefront/tsconfig.json`, `storefront/tailwind.config.js`, `storefront/index.html`, `storefront/postcss.config.js`
- Create: `storefront/src/main.ts`, `storefront/src/App.vue`
- Create: `storefront/src/router/index.ts`
- Create: `storefront/src/stores/index.ts`
- Create: `storefront/src/api/request.ts`, `storefront/src/api/health.ts`
- Create: `storefront/src/layouts/DefaultLayout.vue`
- Create: `storefront/src/views/{Home,Product,Checkout,Login,Register}.vue`
- Create: `storefront/src/components/AppHeader.vue`, `storefront/src/components/AppFooter.vue`
- Create: `storefront/src/assets/main.css`

- [ ] **Step 1: 初始化 Vue3 工程**

```bash
cd /Users/mac/Project/Php/ZCard
mkdir -p storefront
cd storefront
pnpm init
pnpm add vue vue-router@4 pinia axios
pnpm add -D vite @vitejs/plugin-vue typescript vue-tsc tailwindcss @tailwindcss/vite
```

- [ ] **Step 2: package.json scripts**

编辑 `storefront/package.json`，确保 scripts：
```json
{
  "scripts": {
    "dev": "vite",
    "build": "vue-tsc -b && vite build",
    "preview": "vite preview"
  }
}
```

- [ ] **Step 3: vite.config.ts（proxy /api 到 Laravel）**

`storefront/vite.config.ts`：
```ts
import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'
import tailwindcss from '@tailwindcss/vite'

export default defineConfig({
  plugins: [vue(), tailwindcss()],
  server: {
    port: 5173,
    proxy: {
      '/api': {
        target: 'http://localhost',
        changeOrigin: true,
      },
    },
  },
})
```

- [ ] **Step 4: tsconfig.json**

`storefront/tsconfig.json`：
```json
{
  "compilerOptions": {
    "target": "ESNext",
    "module": "ESNext",
    "moduleResolution": "Bundler",
    "strict": true,
    "jsx": "preserve",
    "lib": ["ESNext", "DOM", "DOM.Iterable"],
    "types": ["vite/client"],
    "baseUrl": ".",
    "paths": { "@/*": ["src/*"] }
  },
  "include": ["src/**/*.ts", "src/**/*.vue"]
}
```

- [ ] **Step 5: tailwind.config.js（设计图 token）**

`storefront/tailwind.config.js`：
```js
/** @type {import('tailwindcss').Config} */
export default {
  content: ['./index.html', './src/**/*.{vue,ts}'],
  theme: {
    extend: {
      colors: {
        // spec §5 设计图 token
        primary: '#2563EB',
        success: '#10B981',
        warning: '#F59E0B',
        danger: '#EF4444',
        accent: '#8B5CF6',
        ink: { DEFAULT: '#111827', soft: '#374151', muted: '#6B7280' },
        surface: { DEFAULT: '#FFFFFF', subtle: '#F9FAFB' },
      },
      borderRadius: { card: '8px', field: '4px' },
      boxShadow: { card: '0 1px 3px rgba(0,0,0,0.1)' },
    },
  },
  plugins: [],
}
```

- [ ] **Step 6: index.html + main.css**

`storefront/index.html`：
```html
<!doctype html>
<html lang="zh-CN">
  <head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>ZCard 商城</title>
  </head>
  <body>
    <div id="app"></div>
    <script type="module" src="/src/main.ts"></script>
  </body>
</html>
```

`storefront/src/assets/main.css`：
```css
@import "tailwindcss";
```

- [ ] **Step 7: main.ts + App.vue**

`storefront/src/main.ts`：
```ts
import { createApp } from 'vue'
import { createPinia } from 'pinia'
import App from './App.vue'
import router from './router'
import './assets/main.css'

const app = createApp(App)
app.use(createPinia())
app.use(router)
app.mount('#app')
```

`storefront/src/App.vue`：
```vue
<script setup lang="ts">
import { RouterView } from 'vue-router'
</script>

<template>
  <RouterView />
</template>
```

- [ ] **Step 8: router**

`storefront/src/router/index.ts`：
```ts
import { createRouter, createWebHistory } from 'vue-router'
import DefaultLayout from '@/layouts/DefaultLayout.vue'

const router = createRouter({
  history: createWebHistory(),
  routes: [
    {
      path: '/',
      component: DefaultLayout,
      children: [
        { path: '', name: 'home', component: () => import('@/views/Home.vue') },
        { path: 'product/:id', name: 'product', component: () => import('@/views/Product.vue') },
        { path: 'checkout', name: 'checkout', component: () => import('@/views/Checkout.vue') },
        { path: 'login', name: 'login', component: () => import('@/views/Login.vue') },
        { path: 'register', name: 'register', component: () => import('@/views/Register.vue') },
      ],
    },
  ],
})

export default router
```

- [ ] **Step 9: api 请求封装**

`storefront/src/api/request.ts`：
```ts
import axios from 'axios'

const request = axios.create({
  baseURL: '/api',
  timeout: 10000,
})

// 请求拦截器：带 token（Phase 1 接入认证后填充）
request.interceptors.request.use((config) => {
  const token = localStorage.getItem('zcard_token')
  if (token) config.headers.Authorization = `Bearer ${token}`
  return config
})

// 响应拦截器：统一错误
request.interceptors.response.use(
  (res) => res.data,
  (err) => {
    console.error('[API]', err?.response?.status, err?.message)
    return Promise.reject(err)
  },
)

export default request
```

`storefront/src/api/health.ts`：
```ts
import request from './request'

export interface HealthResp {
  status: string
  service: string
  time: string
}

export const getHealth = () => request.get<unknown, HealthResp>('/health')
```

- [ ] **Step 10: stores 占位**

`storefront/src/stores/index.ts`：
```ts
import { defineStore } from 'pinia'

// Phase 0 占位；Phase 1 加入 user/cart 等 store
export const useAppStore = defineStore('app', {
  state: () => ({ ready: false }),
})
```

- [ ] **Step 11: 布局与组件**

`storefront/src/layouts/DefaultLayout.vue`：
```vue
<script setup lang="ts">
import AppHeader from '@/components/AppHeader.vue'
import AppFooter from '@/components/AppFooter.vue'
</script>

<template>
  <div class="min-h-screen flex flex-col bg-surface">
    <AppHeader />
    <main class="flex-1">
      <RouterView />
    </main>
    <AppFooter />
  </div>
</template>
```

`storefront/src/components/AppHeader.vue`：
```vue
<script setup lang="ts">
import { RouterLink } from 'vue-router'
</script>

<template>
  <header class="bg-white border-b shadow-card">
    <div class="max-w-6xl mx-auto px-4 h-16 flex items-center justify-between">
      <RouterLink to="/" class="text-xl font-bold text-primary">ZCard</RouterLink>
      <nav class="space-x-4 text-ink-soft">
        <RouterLink to="/">首页</RouterLink>
        <RouterLink to="/login">登录</RouterLink>
        <RouterLink to="/register">注册</RouterLink>
      </nav>
    </div>
  </header>
</template>
```

`storefront/src/components/AppFooter.vue`：
```vue
<template>
  <footer class="bg-white border-t mt-12">
    <div class="max-w-6xl mx-auto px-4 py-6 text-center text-ink-muted text-sm">
      © 2026 ZCard · 现代化插件制发卡系统
    </div>
  </footer>
</template>
```

- [ ] **Step 12: 视图（占位，展示配色 + 调通 health）**

`storefront/src/views/Home.vue`：
```vue
<script setup lang="ts">
import { ref, onMounted } from 'vue'
import { getHealth, type HealthResp } from '@/api/health'

const health = ref<HealthResp | null>(null)
const err = ref('')
onMounted(async () => {
  try { health.value = await getHealth() }
  catch (e) { err.value = 'API 未连通（确保 Laravel 已启动）' }
})
</script>

<template>
  <div class="max-w-6xl mx-auto px-4 py-10">
    <div class="rounded-card bg-white shadow-card p-8 mb-6">
      <h1 class="text-3xl font-bold text-ink mb-2">ZCard 商城</h1>
      <p class="text-ink-muted">现代化、插件制虚拟发卡系统（Phase 0 骨架）</p>
      <div class="mt-4 flex gap-3">
        <span class="px-3 py-1 rounded-field bg-primary text-white text-sm">主色 #2563EB</span>
        <span class="px-3 py-1 rounded-field bg-success text-white text-sm">成功</span>
        <span class="px-3 py-1 rounded-field bg-warning text-white text-sm">警告</span>
        <span class="px-3 py-1 rounded-field bg-danger text-white text-sm">危险</span>
        <span class="px-3 py-1 rounded-field bg-accent text-white text-sm">点缀</span>
      </div>
    </div>
    <div class="rounded-card bg-surface-subtle p-6 text-ink-soft">
      API 健康检查：
      <span v-if="health" class="text-success font-mono">{{ health.status }} · {{ health.time }}</span>
      <span v-else-if="err" class="text-danger">{{ err }}</span>
      <span v-else class="text-ink-muted">检测中…</span>
    </div>
  </div>
</template>
```

其余视图 `Product.vue`, `Checkout.vue`, `Login.vue`, `Register.vue` 各创建最简占位（以下为 `Product.vue` 完整内容；其余三个把标题字符串分别换为「收银台」「登录」「注册」，其余结构相同）：

`storefront/src/views/Product.vue`：
```vue
<script setup lang="ts">
const TITLE = '商品详情'
</script>

<template>
  <div class="max-w-6xl mx-auto px-4 py-20 text-center text-ink-muted">
    <h1 class="text-2xl text-ink mb-2">{{ TITLE }}</h1>
    <p>Phase 1 实现</p>
  </div>
</template>
```

- `storefront/src/views/Checkout.vue`：`const TITLE = '收银台'`，模板同上。
- `storefront/src/views/Login.vue`：`const TITLE = '登录'`，模板同上。
- `storefront/src/views/Register.vue`：`const TITLE = '注册'`，模板同上。

- [ ] **Step 13: 跑 dev 验证**

```bash
cd /Users/mac/Project/Php/ZCard/storefront && pnpm dev
```
浏览器开 `http://localhost:5173`：
- 应见首页配色块（主色蓝等）。
- API 健康检查显示 `ok · <时间>`（确保 Laravel `./vendor/bin/sail up -d` 在跑）。
Expected: 视觉与 health 调通。

- [ ] **Step 14: 提交**

```bash
cd /Users/mac/Project/Php/ZCard
git add storefront/ && git commit -m "feat(storefront): init Vue3 + Vite + Tailwind + router + api health"
```

---

## Task 14: README 快速启动文档

**Files:**
- Create: `README.md`

- [ ] **Step 1: 写 README**

`README.md`：
```markdown
# ZCard

> 现代化、插件制虚拟发卡 / 个人店铺系统。Laravel 13 + Filament v5 + Vue3。

## 快速启动（开发环境）

需要：Docker + Node 22 + pnpm。

```bash
# 1. 起后端容器（首次构建镜像约 1-3 分钟）
./vendor/bin/sail up -d

# 2. 初始化系统（迁移 + RBAC + 默认商户 + 超管账号）
./vendor/bin/sail artisan zcard:install
#    会在命令行打印随机 8 位密码，首次登录强制改密

# 3. 后台
#    访问 http://localhost/admin ，用 install 打印的邮箱密码登录

# 4. 前台
cd storefront && pnpm install && pnpm dev
#    访问 http://localhost:5173
```

## 常用命令

| 命令 | 说明 |
|---|---|
| `./vendor/bin/sail up -d` | 起容器（后台） |
| `./vendor/bin/sail artisan migrate` | 跑迁移 |
| `./vendor/bin/sail artisan zcard:install` | 系统初始化（幂等） |
| `./vendor/bin/sail test` | 跑测试 |
| `./vendor/bin/sail composer xxx` | 容器内 composer |
| `cd storefront && pnpm dev` | 前台 dev server |

## 项目结构

- `app/` Laravel 后端
- `app/Filament/Resources/` 后台 CRUD（User, Merchant）
- `storefront/` Vue3 前台（独立工程）
- `plugins/` 插件目录（Phase 2 生效，当前 example-plugin 仅占位）

## 阶段说明

当前为 **Phase 0（地基）**。商品/卡密/订单业务逻辑在 Phase 1，插件系统在 Phase 2。
```

- [ ] **Step 2: 提交**

```bash
git add README.md && git commit -m "docs: add README quickstart"
```

---

## 完成验证（对照 spec §11 验收清单）

全部 Task 完成后，逐项核对：

- [ ] `./vendor/bin/sail up` 一键起容器（PHP8.3 + MySQL8 + Redis）
- [ ] `php artisan migrate` 成功，12 张业务表 + 5 张 spatie 表就位
- [ ] `php artisan zcard:install` 交互式初始化，生成随机 8 位密码
- [ ] 首次登录强制改密（`password_changed_at` 机制生效）
- [ ] `/admin` 能登录，看到 User/Merchant 两个 Resource，主题是设计图蓝 `#2563EB`
- [ ] `/api/health` 返回 JSON，前台 axios 能调通
- [ ] `storefront/` `pnpm dev` 起来，首页展示设计图配色
- [ ] `plugins/example-plugin/` 骨架存在，结构清晰
- [ ] 首个/各 git commit 只含骨架（`.gitignore` 忽略 `docs/`、storefront/node_modules）
```
