# ZCard P1-A — 商品管理 + 前台商品只读 实现计划

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** 在 Phase 0 地基上实现后台商品/分类/SKU 管理 + 店铺外观设置 + 前台商品只读(列表/详情/首页推荐),配置驱动 UI。

**Architecture:** Filament v5 后台(Resource + 自定义设置页)写商品/分类/设置;Laravel API 只读查询;Vue3 storefront 按 `settings(storefront)` 配置渲染。SKU 用 Repeater+relationship,自定义控件用 Repeater(JSON),分类用 DIY 树形(Select + 缩进 TextColumn)。

**Tech Stack:** Laravel 13, Filament v5(`form(Schema $schema): Schema`、Toggle、ToggleButtons::grouped()、Repeater、FileUpload),MySQL 8,Vue3 + Vite + Tailwind v4 + Pinia。

**对应 spec:** `docs/superpowers/specs/2026-07-29-zcard-p1a-product-management-design.md`。

**v5 关键 API(已确认):**
- `form(Schema $schema): Schema` via `Filament\Schemas\Schema`(取代 `Form $form`)
- Toggle = iOS 左右开关;`ToggleButtons::grouped()` = 分段按钮组
- `Repeater::make('skus')->relationship()` 管 HasMany;JSON 列加 `array` cast
- FileUpload 默认按磁盘名判可见性 → 用名为 `public` 的磁盘
- 设置页:自定义 Page + Setting 模型 + `InteractsWithForms`(spec 已有 settings 表)

---

## 环境前提

- Phase 0 已完成:容器在跑(`./vendor/bin/sail up -d`),app 在 :8092,storefront 在 :5173。
- 所有 PHP/artisan/composer 命令走 `./vendor/bin/sail`(简写 `sail`)。
- 本计划沿用 Phase 0 的端口与模式。

---

## 文件结构总览

```
app/
├── Filament/
│   ├── Resources/
│   │   ├── Categories/              # Task 4
│   │   │   └── CategoryResource.php (+ Schemas/Tables/Pages)
│   │   ├── Products/                # Task 5,6,7
│   │   │   └── ProductResource.php (+ Schemas/Tables/Pages/RelationManagers/SkusRelationManager)
│   │   └── (Phase0: Users/, Merchants/)
│   └── Pages/StorefrontSettings.php # Task 8 (自定义设置页)
├── Http/Controllers/Api/
│   ├── CategoryController.php       # Task 9
│   ├── ProductController.php        # Task 9
│   └── StorefrontSettingsController.php # Task 9
├── Models/
│   ├── Product.php (改)             # Task 2
│   ├── ProductSku.php (新)          # Task 2
│   └── Setting.php (改,加辅助)      # Task 3
└── Support/
    └── StorefrontConfig.php         # Task 3 (读写 settings 辅助)
database/migrations/
├── 2026_07_29_000010_alter_products_add_p1a_columns.php  # Task 1
└── 2026_07_29_000020_create_product_skus_table.php        # Task 1
routes/api.php (改)                   # Task 9
storefront/src/
├── api/{products,categories,settings}.ts # Task 10
├── stores/{settings,products}.ts     # Task 10
├── components/{ProductCard,ViewSwitcher,CategoryNav,HotTags}.vue # Task 11
├── views/{Home,ProductDetail}.vue (改) # Task 11,12
resources/views/filament/pages/storefront-settings.blade.php # Task 8
```

---

## Task 1: 数据库变更(products 加列 + product_skus 表)

**Files:**
- Create: `database/migrations/2026_07_29_000010_alter_products_add_p1a_columns.php`
- Create: `database/migrations/2026_07_29_000020_create_product_skus_table.php`

- [ ] **Step 1: 创建 products 加列迁移**

`database/migrations/2026_07_29_000010_alter_products_add_p1a_columns.php`:
```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->boolean('is_featured')->default(false)->after('status');
            $table->unsignedInteger('virtual_sales')->default(0)->after('is_featured');
            $table->json('virtual_reviews')->nullable()->after('virtual_sales');
            $table->unsignedInteger('min_order')->default(1)->after('virtual_reviews');
            $table->unsignedInteger('max_order')->default(0)->after('min_order'); // 0=不限
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['is_featured', 'virtual_sales', 'virtual_reviews', 'min_order', 'max_order']);
        });
    }
};
```

- [ ] **Step 2: 创建 product_skus 表迁移**

`database/migrations/2026_07_29_000020_create_product_skus_table.php`:
```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('product_skus', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->string('name', 60);
            $table->bigInteger('price')->default(0)->comment('单位分');
            $table->string('stock_type', 20)->nullable()->comment('card/url/code;NULL=继承商品');
            $table->unsignedInteger('sort')->default(0);
            $table->boolean('status')->default(true);
            $table->timestamps();
            $table->index(['product_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_skus');
    }
};
```

- [ ] **Step 3: 跑迁移验证**

```bash
./vendor/bin/sail artisan migrate
```
Expected: 两个迁移 DONE,无报错。

- [ ] **Step 4: 验证 product_skus 表与 products 新列**

```bash
./vendor/bin/sail artisan tinker --execute="echo implode(',', Schema::getColumnListing('product_skus'));"
```
Expected: `id,product_id,name,price,stock_type,sort,status,created_at,updated_at`

- [ ] **Step 5: 提交**

```bash
git add database/migrations/ && git commit -m "feat(db): P1-A products columns + product_skus table"
```

---

## Task 2: Product 模型改 + ProductSku 模型

**Files:**
- Modify: `app/Models/Product.php`
- Create: `app/Models/ProductSku.php`

- [ ] **Step 1: 改 Product 模型(fillable + casts + skus 关系 + 辅助方法)**

完全替换 `app/Models/Product.php`:
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
        // P1-A 新增
        'is_featured', 'virtual_sales', 'virtual_reviews', 'min_order', 'max_order',
    ];

    protected function casts(): array
    {
        return [
            'member_price' => 'array',
            'images' => 'array',
            'control_config' => 'array',
            'virtual_reviews' => 'array',
            'stock_visible' => 'boolean',
            'is_featured' => 'boolean',
        ];
    }

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function skus(): HasMany
    {
        return $this->hasMany(ProductSku::class);
    }

    public function cards(): HasMany
    {
        return $this->hasMany(Card::class);
    }

    /** 可用卡密库存数(cards WHERE unused) */
    public function availableStock(): int
    {
        return (int) $this->cards()->where('status', Card::STATUS_UNUSED)->count();
    }

    /** 展示销量 = 真实销量 + 虚拟销量。真实销量留 P1-C(暂为0)。 */
    public function displaySales(): int
    {
        // P1-C 后:真实销量 = paid 订单 quantity 之和。P1-A 阶段真实=0。
        return $this->virtual_sales;
    }
}
```

- [ ] **Step 2: 创建 ProductSku 模型**

`app/Models/ProductSku.php`:
```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductSku extends Model
{
    protected $fillable = [
        'product_id', 'name', 'price', 'stock_type', 'sort', 'status',
    ];

    protected function casts(): array
    {
        return ['status' => 'boolean'];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
```

- [ ] **Step 3: 改 Category 模型加 parent/children/products 关系**

完全替换 `app/Models/Category.php`:
```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Category extends Model
{
    protected $fillable = ['parent_id', 'merchant_id', 'name', 'slug', 'sort', 'status'];

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('sort');
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    /** 树形列表展示用的缩进名 */
    public function getIndentedNameAttribute(): string
    {
        $depth = 0;
        $p = $this->parent;
        while ($p) {
            $depth++;
            $p = $p->parent;
        }
        return str_repeat('— ', $depth) . $this->name;
    }
}
```

- [ ] **Step 4: 提交**

```bash
git add app/Models/ && git commit -m "feat(model): Product P1-A fields, ProductSku, Category tree relations"
```

---

## Task 3: StorefrontConfig 辅助 + Setting 模型辅助

**Files:**
- Create: `app/Support/StorefrontConfig.php`
- Modify: `app/Models/Setting.php`

- [ ] **Step 1: 创建 StorefrontConfig(读写 settings 的辅助 + 默认值)**

`app/Support/StorefrontConfig.php`:
```php
<?php

namespace App\Support;

use App\Models\Setting;

/**
 * 店铺外观配置辅助(spec §3.3, group=storefront)。
 * 读写 settings 表;提供默认值。
 */
class StorefrontConfig
{
    /** 所有配置 key 及默认值 */
    public static function defaults(): array
    {
        return [
            'category_nav_style' => 'pills',
            'list_default_view' => 'grid',
            'grid_columns' => 4,
            'page_size' => 12,
            'default_order' => 'newest',
            'show_stock' => true,
            'show_sales' => true,
            'show_reviews' => false,
            'allow_post_review' => true,
            'review_need_audit' => true,
            'show_featured' => true,
            'featured_count' => 8,
            'show_hot_tags' => true,
            'hot_tag_categories' => [],
            'order_query_password' => true,
            'trade_captcha' => true,
        ];
    }

    /** 取全部配置(合并默认值),数组返回 */
    public static function all(): array
    {
        $rows = Setting::where('group', 'storefront')->pluck('value', 'key');
        $merged = self::defaults();
        foreach ($merged as $key => $default) {
            if (isset($rows[$key])) {
                $merged[$key] = $rows[$key];
            }
        }
        // value 列是 json cast,pluck 后可能是 array
        return $merged;
    }

    /** 取单个值 */
    public static function get(string $key): mixed
    {
        return self::all()[$key] ?? null;
    }

    /** 批量保存 */
    public static function setMany(array $kv): void
    {
        foreach ($kv as $key => $value) {
            Setting::updateOrCreate(
                ['key' => $key],
                ['value' => $value, 'group' => 'storefront']
            );
        }
    }
}
```

- [ ] **Step 2: 给 Setting 模型补 fillable(已存在,确认)**

检查 `app/Models/Setting.php` 已有:
```php
protected $fillable = ['key', 'value', 'group'];
protected function casts(): array { return ['value' => 'array']; }
```
(Phase 0 已建,无需改动。)

- [ ] **Step 3: tinker 验证默认值合并**

```bash
./vendor/bin/sail artisan tinker --execute="print_r(App\Support\StorefrontConfig::all());"
```
Expected: 输出 16 项配置,均为默认值(settings 表暂无 storefront 行)。

- [ ] **Step 4: 提交**

```bash
git add app/Support/StorefrontConfig.php && git commit -m "feat(config): StorefrontConfig helper for storefront settings"
```

---

## Task 4: CategoryResource(树形 CRUD)

**Files:**
- Create: `app/Filament/Resources/Categories/CategoryResource.php` + Pages/Schemas/Tables
- Run shield:generate

- [ ] **Step 1: 生成 CategoryResource**

```bash
./vendor/bin/sail artisan filament:resource Category --no-interactive
```

- [ ] **Step 2: 编辑 CategoryForm(配置 parent_id Select + 基础字段)**

打开生成的 `app/Filament/Resources/Categories/Schemas/CategoryForm.php`,替换 `components`:
```php
<?php

namespace App\Filament\Resources\Categories\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class CategoryForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('parent_id')
                    ->label('父分类')
                    ->relationship('parent', 'name', ignoreRecord: true)
                    ->placeholder('顶级分类')
                    ->nullable(),
                TextInput::make('name')->required()->maxLength(100)->label('名称'),
                TextInput::make('slug')->required()->maxLength(100)->label('Slug')
                    ->hint('唯一标识,留空自动生成'),
                TextInput::make('sort')->numeric()->default(0)->label('排序'),
                Toggle::make('status')->default(true)->label('启用'),
            ]);
    }
}
```

- [ ] **Step 3: 编辑 CategoriesTable(缩进名展示)**

打开 `app/Filament/Resources/Categories/Tables/CategoriesTable.php`,替换 `columns`:
```php
<?php

namespace App\Filament\Resources\Categories\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Table;

class CategoriesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('sort')
            ->columns([
                TextColumn::make('indented_name')->label('名称')->searchable(),
                TextColumn::make('slug')->label('Slug')->toggleable(),
                TextColumn::make('sort')->label('排序')->alignRight(),
                ToggleColumn::make('status')->label('启用'),
                TextColumn::make('created_at')->dateTime()->label('创建时间')->toggleable(),
            ])
            ->filters([
                //
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
```

- [ ] **Step 4: 验证后台能访问分类页**

```bash
./vendor/bin/sail artisan optimize:clear
curl -sI http://localhost:8092/admin | head -1
```
Expected: 302(跳登录,后台正常)。然后用浏览器登录后台,左侧应见 Categories。

- [ ] **Step 5: 生成 shield 权限**

```bash
./vendor/bin/sail artisan shield:generate --all --panel=admin --no-interaction
```

- [ ] **Step 6: 提交**

```bash
git add app/Filament/Resources/Categories/ && git commit -m "feat(filament): Category resource with tree display"
```

---

## Task 5: ProductResource 基础(核心字段 + 分类关联 + 图片上传)

**Files:**
- Modify: `app/Filament/Resources/Products/Schemas/ProductForm.php`(生成后改)
- Modify: `app/Filament/Resources/Products/Tables/ProductsTable.php`
- Run `php artisan storage:link`

- [ ] **Step 1: 生成 ProductResource**

```bash
./vendor/bin/sail artisan filament:resource Product --no-interactive
```

- [ ] **Step 2: 建 storage 软链接(图片访问)**

```bash
./vendor/bin/sail artisan storage:link
```
Expected: `The [public/storage] link has been connected to [storage/app/public].`(或已存在)

- [ ] **Step 3: 编辑 ProductForm(基础 + 分类 + 价格 + 图片)**

完全替换 `app/Filament/Resources/Products/Schemas/ProductForm.php`:
```php
<?php

namespace App\Filament\Resources\Products\Schemas;

use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\Textarea;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class ProductForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('基础信息')
                    ->schema([
                        TextInput::make('name')->required()->maxLength(150)->label('商品名'),
                        TextInput::make('slug')->required()->maxLength(150)->label('Slug')
                            ->hint('留空自动生成'),
                        Select::make('category_id')
                            ->relationship('category', 'name')
                            ->nullable()->label('分类'),
                        Textarea::make('description')->label('商品描述')->columnSpanFull(),
                    ])->columns(2),

                Section::make('价格与库存')
                    ->schema([
                        TextInput::make('price')->numeric()->required()->default(0)
                            ->prefix('分')->label('价格(分)'),
                        Select::make('stock_type')
                            ->options(['card' => '卡密', 'url' => '链接', 'code' => '兑换码'])
                            ->default('card')->label('库存类型'),
                        Toggle::make('stock_visible')->default(true)->label('显示库存数'),
                        TextInput::make('member_price')
                            ->hint('JSON: {等级:价格},Phase 3 生效')->label('会员价JSON'),
                    ])->columns(2),

                Section::make('配图')
                    ->schema([
                        FileUpload::make('cover')
                            ->image()->directory('products/covers')->disk('public')
                            ->imageEditor()->maxSize(5120)->label('封面图'),
                        FileUpload::make('images')
                            ->multiple()->image()->directory('products/gallery')->disk('public')
                            ->reorderable()->maxParallelUploads(3)->label('详情图(多图)'),
                    ])->columns(2),

                Section::make('上架设置')
                    ->schema([
                        Select::make('delivery_mode')
                            ->options(['status' => '保留(置used)', 'delete' => '物理删除'])
                            ->default('status')->label('发放模式'),
                        TextInput::make('sort')->numeric()->default(0)->label('排序'),
                        Toggle::make('status')->default(true)->label('上架'),
                    ])->columns(2),
            ]);
    }
}
```

- [ ] **Step 4: 编辑 ProductsTable(列表展示)**

完全替换 `app/Filament/Resources/Products/Tables/ProductsTable.php`:
```php
<?php

namespace App\Filament\Resources\Products\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Table;

class ProductsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('sort')
            ->columns([
                ImageColumn::make('cover')->label('封面')->circular(),
                TextColumn::make('name')->label('商品名')->searchable()->limit(30),
                TextColumn::make('category.name')->label('分类')->toggleable(),
                TextColumn::make('price')->label('价格(分)')->money('CNY', divideBy: 100, locale: 'zh_CN'),
                IconColumn::make('is_featured')->boolean()->label('推荐')->toggleable(),
                ToggleColumn::make('status')->label('上架'),
                TextColumn::make('sort')->label('排序')->alignRight()->toggleable(),
            ])
            ->filters([
                //
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
```

- [ ] **Step 5: 验证后台 Product 列表可访问**

```bash
./vendor/bin/sail artisan optimize:clear
```
浏览器登录后台 → 应见 Products,能新建商品(填字段 + 上传图片)。

- [ ] **Step 6: 提交**

```bash
git add app/Filament/Resources/Products/ && git commit -m "feat(filament): Product resource core (fields, category, image upload)"
```

---

## Task 6: ProductForm 加 SKU + 自定义控件 + 营销虚拟数据 + 限购

**Files:**
- Modify: `app/Filament/Resources/Products/Schemas/ProductForm.php`(加 Section)

- [ ] **Step 1: Product 模型确认有 skus() 关系(Task 2 已加)**

(已在 Task 2 Step 1 添加 `skus()` HasMany。跳过。)

- [ ] **Step 2: ProductForm 加 SKU Section(Repeater + relationship)**

在 ProductForm 的 `components` 数组中,在「上架设置」Section 之前插入 SKU Section。修改 `app/Filament/Resources/Products/Schemas/ProductForm.php`,在最后的 `Section::make('上架设置')` 之前加入:
```php
use Filament\Forms\Components\Repeater;
// ...
                Section::make('规格(SKU)')
                    ->schema([
                        Repeater::make('skus')
                            ->relationship()
                            ->schema([
                                TextInput::make('name')->required()->label('规格名(如月卡)'),
                                TextInput::make('price')->numeric()->required()->prefix('分')->label('价格(分)'),
                                Select::make('stock_type')
                                    ->options(['card' => '卡密', 'url' => '链接', 'code' => '兑换码'])
                                    ->placeholder('继承商品')->label('库存类型'),
                                TextInput::make('sort')->numeric()->default(0)->label('排序'),
                                Toggle::make('status')->default(true)->label('启用'),
                            ])
                            ->columns(3)
                            ->reorderable('sort')
                            ->defaultItems(0)
                            ->hint('留空则为单规格商品,用上方价格'),
                    ])
                    ->collapsed(),

                Section::make('自定义控件(下单时让顾客填写)')
                    ->schema([
                        Repeater::make('control_config')
                            ->schema([
                                Select::make('type')
                                    ->options([
                                        'text' => '文本', 'email' => '邮箱',
                                        'textarea' => '多行文本', 'select' => '下拉',
                                    ])->live()->required()->label('类型'),
                                TextInput::make('label')->required()->label('标签'),
                                TextInput::make('name')->required()->label('字段名'),
                                Toggle::make('required')->label('必填'),
                                TextInput::make('options')
                                    ->label('下拉选项(逗号分隔)')
                                    ->visible(fn ($get) => $get('type') === 'select'),
                            ])
                            ->columns(3)
                            ->defaultItems(0)
                            ->hint('产出 control_config JSON,P1-C 下单页渲染'),
                    ])
                    ->collapsed(),

                Section::make('营销虚拟数据')
                    ->schema([
                        Toggle::make('is_featured')->label('加入首页推荐'),
                        TextInput::make('virtual_sales')->numeric()->default(0)
                            ->label('虚拟销量基数(前台显示=真实+此数)'),
                        TextInput::make('virtual_reviews')
                            ->hint('JSON: {"rating":4.8,"count":156} 或含 list 数组')
                            ->label('虚拟评论JSON'),
                    ])
                    ->columns(2)
                    ->collapsed(),

                Section::make('限购')
                    ->schema([
                        TextInput::make('min_order')->numeric()->default(1)->label('最小购买量'),
                        TextInput::make('max_order')->numeric()->default(0)
                            ->label('最大购买量(0=不限)'),
                    ])
                    ->columns(2)
                    ->collapsed(),
```

> `Repeater::make('skus')->relationship()` 要求 Product 模型有 `skus()` 关系(Task 2 已加)。`control_config`/`virtual_reviews` 已在 Product casts 设为 array(Task 2)。

- [ ] **Step 3: 验证新建商品能填所有字段(含 SKU 行、控件行)**

浏览器后台 → Products → 新建 → 应见所有 Section,能添加 SKU 行、自定义控件行,保存成功。

- [ ] **Step 4: 提交**

```bash
git add app/Filament/Resources/Products/Schemas/ProductForm.php && git commit -m "feat(filament): Product form - SKU repeater, control_config, marketing, limits"
```

---

## Task 7: SkusRelationManager(独立 SKU 管理标签页)

**Files:**
- Create: `app/Filament/Resources/Products/RelationManagers/SkusRelationManager.php`
- Modify: `app/Filament/Resources/Products/ProductResource.php`(注册 RelationManager)
- Run shield:generate

- [ ] **Step 1: 创建 RelationManager**

```bash
./vendor/bin/sail artisan make:filament-relation-manager ProductResource product skus --no-interactive
```
(参数:ProductResource / 关系名 product / 关系方法 skus。生成 SkusRelationManager。)

- [ ] **Step 2: 检查 ProductResource 自动注册了 RelationManager**

```bash
grep -n "RelationManagers\|getRelations" app/Filament/Resources/Products/ProductResource.php
```
Expected: 命令会自动在 `getRelations()` 注册 SkusRelationManager。

- [ ] **Step 3: 编辑 SkusRelationManager 表单与表格**

打开 `app/Filament/Resources/Products/RelationManagers/SkusRelationManager.php`,确认 form 含 name/price/stock_type/sort/status,table 含对应列。命令生成后默认字段,手动调整为:
```php
// form
TextInput::make('name')->required()->label('规格名'),
TextInput::make('price')->numeric()->required()->prefix('分')->label('价格(分)'),
Select::make('stock_type')->options(['card' => '卡密', 'url' => '链接', 'code' => '兑换码'])->placeholder('继承商品')->label('库存类型'),
TextInput::make('sort')->numeric()->default(0)->label('排序'),
Toggle::make('status')->default(true)->label('启用'),
```

- [ ] **Step 4: 重新生成 shield 权限(含新 Resource)**

```bash
./vendor/bin/sail artisan shield:generate --all --panel=admin --no-interaction
./vendor/bin/sail artisan optimize:clear
```

- [ ] **Step 5: 验证商品编辑页底部有 SKU 管理标签**

浏览器后台 → 编辑某商品 → 底部应见「Skus」标签,可独立增删改 SKU。

- [ ] **Step 6: 提交**

```bash
git add app/Filament/Resources/Products/ && git commit -m "feat(filament): SkusRelationManager for product SKU management"
```

> **注:** spec §4.1 提到的「独立 ProductSkuResource」P1-A 暂不做。Repeater(Task 6)+ RelationManager(本 Task)已完整覆盖 SKU 的增删改查(编辑商品时内联 + 底部标签页)。独立 Resource 仅用于跨商品批量列表查看,非刚需,留后续。

---

## Task 8: 店铺外观设置页(自定义 Page,大厂风格)

**Files:**
- Create: `app/Filament/Pages/StorefrontSettings.php`
- Create: `resources/views/filament/pages/storefront-settings.blade.php`
- Modify: `app/Providers/Filament/AdminPanelProvider.php`(注册 Page)

- [ ] **Step 1: 创建视图目录与 Blade**

```bash
mkdir -p resources/views/filament/pages
```

`resources/views/filament/pages/storefront-settings.blade.php`:
```blade
<x-filament-panels::page>
    {{ $this->form }}
</x-filament-panels::page>
```

- [ ] **Step 2: 创建 StorefrontSettings Page**

`app/Filament/Pages/StorefrontSettings.php`:
```php
<?php

namespace App\Filament\Pages;

use App\Support\StorefrontConfig;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\ToggleButtons;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class StorefrontSettings extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-paint-brush';
    protected static string $view = 'filament.pages.storefront-settings';
    protected static ?string $navigationGroup = '设置';
    protected static ?string $navigationLabel = '店铺外观';
    protected static ?int $navigationSort = 1;

    public ?array $data = [];

    public function mount(): void
    {
        $this->form->fill(StorefrontConfig::all());
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                Section::make('商品列表布局')
                    ->schema([
                        ToggleButtons::make('category_nav_style')
                            ->options(['pills' => '顶部标签', 'sidebar' => '左侧树', 'combo' => '组合'])
                            ->grouped()->inline()->default('pills')->label('分类导航样式'),
                        ToggleButtons::make('list_default_view')
                            ->options(['grid' => '网格', 'list' => '列表', 'dual' => '双栏'])
                            ->grouped()->inline()->default('grid')->label('默认视图'),
                        ToggleButtons::make('grid_columns')
                            ->options([3 => '3', 4 => '4', 5 => '5'])
                            ->grouped()->inline()->default(4)->label('网格每行数'),
                        TextInput::make('page_size')->numeric()->default(12)->label('每页商品数'),
                        ToggleButtons::make('default_order')
                            ->options(['newest' => '最新', 'price_asc' => '价格升', 'price_desc' => '价格降', 'sort' => '手动'])
                            ->grouped()->inline()->default('newest')->label('默认排序'),
                    ])->columns(2),

                Section::make('展示项')
                    ->schema([
                        Toggle::make('show_stock')->label('显示库存数'),
                        Toggle::make('show_sales')->label('显示销量'),
                        Toggle::make('show_reviews')->label('显示评价'),
                        Toggle::make('allow_post_review')->label('允许用户发布评价'),
                        Toggle::make('review_need_audit')->label('评价需要审核'),
                    ])->columns(2),

                Section::make('首页推荐')
                    ->schema([
                        Toggle::make('show_featured')->label('启用首页推荐'),
                        TextInput::make('featured_count')->numeric()->default(8)->label('推荐位商品数'),
                    ])->columns(2),

                Section::make('下单设置(P1-C 收银台消费)')
                    ->schema([
                        Toggle::make('order_query_password')->label('启用查询密码')->hint('下单时设密码,凭邮箱+密码查单'),
                        Toggle::make('trade_captcha')->label('启用人机验证')->hint('下单时图形验证码'),
                    ])->columns(2),

                Section::make('热门标签')
                    ->schema([
                        Toggle::make('show_hot_tags')->label('显示热门标签'),
                        Select::make('hot_tag_categories')
                            ->relationship('hotCategories', 'name', ignoreRecord: true)
                            ->multiple()->label('热门标签分类'),
                    ])->columns(2),
            ]);
    }

    public function save(): void
    {
        $data = $this->form->getState();
        StorefrontConfig::setMany($data);
        Notification::make()->success()->title('已保存店铺外观设置')->send();
    }

    protected function getFormActions(): array
    {
        return [
            \Filament\Actions\Action::make('save')->label('保存')->submit('save'),
        ];
    }
}
```

> 注:`hot_tag_categories` 用 relationship 需在 Setting 模型或独立关系。简化:**改为纯多选 category_id 列表**(不用 relationship)。Step 3 调整。

- [ ] **Step 3: hot_tag_categories 改为多选 Category(非 relationship,存 id 数组)**

修改 Step 2 中 `Select::make('hot_tag_categories')` 为:
```php
Select::make('hot_tag_categories')
    ->options(\App\Models\Category::orderBy('sort')->pluck('name', 'id'))
    ->multiple()->searchable()->label('热门标签分类'),
```
StorefrontConfig::setMany 会把数组存进 settings.value(json cast)。

- [ ] **Step 4: 注册 Page 到 AdminPanelProvider**

修改 `app/Providers/Filament/AdminPanelProvider.php`,在 `->pages([Dashboard::class])` 改为:
```php
->pages([
    Dashboard::class,
    \App\Filament\Pages\StorefrontSettings::class,
])
```

- [ ] **Step 5: 验证设置页可访问可保存**

```bash
./vendor/bin/sail artisan optimize:clear
```
浏览器后台 → 左侧「设置 → 店铺外观」→ 应见各 Section(Toggle 左右滑、ToggleButtons 分段按钮)→ 改几项 → 保存 → 刷新设置应保留。

- [ ] **Step 6: 提交**

```bash
git add app/Filament/Pages/ resources/views/filament/ app/Providers/Filament/AdminPanelProvider.php && git commit -m "feat(filament): StorefrontSettings page (big-tech style, toggles + togglebuttons)"
```

---

## Task 9: 前台 API(分类/商品/设置/推荐)

**Files:**
- Modify: `routes/api.php`
- Create: `app/Http/Controllers/Api/CategoryController.php`
- Create: `app/Http/Controllers/Api/ProductController.php`
- Create: `app/Http/Controllers/Api/StorefrontSettingsController.php`

- [ ] **Step 1: CategoryController(树形)**

`app/Http/Controllers/Api/CategoryController.php`:
```php
<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Category;
use Illuminate\Http\JsonResponse;

class CategoryController extends Controller
{
    public function index(): JsonResponse
    {
        $tree = Category::whereNull('parent_id')
            ->where('status', true)
            ->orderBy('sort')
            ->with(['children' => fn ($q) => $q->where('status', true)->orderBy('sort')])
            ->get(['id', 'name', 'slug', 'parent_id']);

        return response()->json($tree);
    }
}
```

- [ ] **Step 2: ProductController(列表/详情/推荐)**

`app/Http/Controllers/Api/ProductController.php`:
```php
<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Support\StorefrontConfig;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProductController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $pageSize = (int) StorefrontConfig::get('page_size');
        $order = $request->input('order', StorefrontConfig::get('default_order'));

        $query = Product::where('status', true)
            ->with(['skus' => fn ($q) => $q->where('status', true)->orderBy('sort')])
            ->withCount(['cards as stock' => fn ($q) => $q->where('status', 'unused')]);

        if ($categoryId = $request->input('category')) {
            $query->where('category_id', $categoryId);
        }

        $query = match ($order) {
            'price_asc' => $query->orderBy('price'),
            'price_desc' => $query->orderByDesc('price'),
            'sort' => $query->orderBy('sort'),
            default => $query->latest(),
        };

        $products = $query->paginate($pageSize);
        $products->getCollection()->transform(fn ($p) => $this->transform($p));

        return response()->json($products);
    }

    public function show(string $slug): JsonResponse
    {
        $product = Product::where('slug', $slug)
            ->where('status', true)
            ->with(['skus' => fn ($q) => $q->where('status', true)->orderBy('sort')])
            ->withCount(['cards as stock' => fn ($q) => $q->where('status', 'unused')])
            ->firstOrFail();

        return response()->json($this->transform($product, true));
    }

    public function featured(Request $request): JsonResponse
    {
        $count = (int) ($request->input('limit', StorefrontConfig::get('featured_count')));
        $products = Product::where('status', true)->where('is_featured', true)
            ->latest()->limit($count)
            ->withCount(['cards as stock' => fn ($q) => $q->where('status', 'unused')])
            ->get()->map(fn ($p) => $this->transform($p));

        return response()->json($products);
    }

    /** 统一输出格式:金额分,加 sales/stock */
    private function transform(Product $p, bool $detail = false): array
    {
        $data = [
            'id' => $p->id,
            'name' => $p->name,
            'slug' => $p->slug,
            'cover' => $p->cover,
            'price' => (int) $p->price,
            'stock' => (int) $p->stock,
            'sales' => $p->displaySales(),
            'is_featured' => (bool) $p->is_featured,
        ];
        if ($detail) {
            $data = array_merge($data, [
                'description' => $p->description,
                'images' => $p->images ?? [],
                'category' => $p->category?->only(['id', 'name', 'slug']),
                'skus' => $p->skus->map(fn ($s) => [
                    'id' => $s->id, 'name' => $s->name,
                    'price' => (int) $s->price, 'stock' => (int) $p->stock,
                ]),
                'virtual_reviews' => $p->virtual_reviews,
                'min_order' => $p->min_order, 'max_order' => $p->max_order,
                'stock_type' => $p->stock_type, 'delivery_mode' => $p->delivery_mode,
            ]);
        }
        return $data;
    }
}
```

- [ ] **Step 3: StorefrontSettingsController(供前台读配置)**

`app/Http/Controllers/Api/StorefrontSettingsController.php`:
```php
<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\StorefrontConfig;
use Illuminate\Http\JsonResponse;

class StorefrontSettingsController extends Controller
{
    public function show(): JsonResponse
    {
        return response()->json(StorefrontConfig::all());
    }
}
```

- [ ] **Step 4: 注册路由**

修改 `routes/api.php`,在 `/health` 路由后加:
```php
use App\Http\Controllers\Api\CategoryController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\StorefrontSettingsController;

Route::get('/categories', [CategoryController::class, 'index'])->name('api.categories');
Route::get('/products', [ProductController::class, 'index'])->name('api.products');
Route::get('/products/featured', [ProductController::class, 'featured'])->name('api.products.featured');
Route::get('/products/{slug}', [ProductController::class, 'show'])->name('api.products.show');
Route::get('/settings/storefront', [StorefrontSettingsController::class, 'show'])->name('api.settings.storefront');
```
> 注意 `/products/featured` 要在 `/products/{slug}` 之前(避免 featured 被当 slug)。

- [ ] **Step 5: 验证 API**

```bash
./vendor/bin/sail artisan route:clear
curl -s http://localhost:8092/api/settings/storefront | head -c 200
echo ""
curl -s http://localhost:8092/api/products | head -c 200
```
Expected: storefront 返回配置 JSON;products 返回分页 JSON(无商品时 data 为空数组)。

- [ ] **Step 6: 提交**

```bash
git add app/Http/Controllers/Api/ routes/api.php && git commit -m "feat(api): category tree, product list/detail/featured, storefront settings"
```

---

## Task 10: 前台 API 封装 + Pinia store

**Files:**
- Create: `storefront/src/api/categories.ts`
- Create: `storefront/src/api/products.ts`
- Create: `storefront/src/api/settings.ts`
- Create: `storefront/src/stores/settings.ts`
- Create: `storefront/src/stores/products.ts`

- [ ] **Step 1: API 封装**

`storefront/src/api/categories.ts`:
```ts
import request from './request'

export interface Category {
  id: number; name: string; slug: string; parent_id: number | null
  children?: Category[]
}
export const getCategories = () => request.get<unknown, Category[]>('/categories')
```

`storefront/src/api/products.ts`:
```ts
import request from './request'

export interface Sku { id: number; name: string; price: number; stock: number }
export interface Product {
  id: number; name: string; slug: string; cover: string | null; price: number
  stock: number; sales: number; is_featured: boolean
  description?: string; images?: string[]; category?: { id: number; name: string; slug: string }
  skus?: Sku[]; virtual_reviews?: { rating?: number; count?: number; list?: any[] }
  min_order?: number; max_order?: number; stock_type?: string; delivery_mode?: string
}
export interface Paginated {
  data: Product[]; current_page: number; last_page: number; total: number
}
export const getProducts = (params: Record<string, any> = {}) =>
  request.get<unknown, Paginated>('/products', { params })
export const getProduct = (slug: string) =>
  request.get<unknown, Product>(`/products/${slug}`)
export const getFeatured = (limit?: number) =>
  request.get<unknown, Product[]>('/products/featured', { params: limit ? { limit } : {} })
```

`storefront/src/api/settings.ts`:
```ts
import request from './request'

export interface StorefrontSettings {
  category_nav_style: 'pills' | 'sidebar' | 'combo'
  list_default_view: 'grid' | 'list' | 'dual'
  grid_columns: number
  page_size: number
  default_order: string
  show_stock: boolean; show_sales: boolean; show_reviews: boolean
  allow_post_review: boolean; review_need_audit: boolean
  show_featured: boolean; featured_count: number
  show_hot_tags: boolean; hot_tag_categories: number[]
  order_query_password: boolean; trade_captcha: boolean
}
export const getStorefrontSettings = () =>
  request.get<unknown, StorefrontSettings>('/settings/storefront')
```

- [ ] **Step 2: settings store(启动时加载配置)**

`storefront/src/stores/settings.ts`:
```ts
import { defineStore } from 'pinia'
import { getStorefrontSettings, type StorefrontSettings } from '@/api/settings'

export const useSettingsStore = defineStore('settings', {
  state: () => ({
    config: null as StorefrontSettings | null,
    loaded: false,
    view: (localStorage.getItem('zcard_view') || '') as 'grid' | 'list' | 'dual' | '',
  }),
  getters: {
    effectiveView(state): 'grid' | 'list' | 'dual' {
      return state.view || state.config?.list_default_view || 'grid'
    },
  },
  actions: {
    async load() {
      if (this.loaded) return
      this.config = await getStorefrontSettings()
      this.loaded = true
    },
    setView(v: 'grid' | 'list' | 'dual') {
      this.view = v
      localStorage.setItem('zcard_view', v)
    },
  },
})
```

- [ ] **Step 3: products store**

`storefront/src/stores/products.ts`:
```ts
import { defineStore } from 'pinia'
import { getProducts, type Product } from '@/api/products'

export const useProductsStore = defineStore('products', {
  state: () => ({
    list: [] as Product[],
    page: 1,
    lastPage: 1,
    loading: false,
  }),
  actions: {
    async fetch(params: Record<string, any> = {}) {
      this.loading = true
      try {
        const res = await getProducts(params)
        this.list = res.data
        this.page = res.current_page
        this.lastPage = res.last_page
      } finally {
        this.loading = false
      }
    },
  },
})
```

- [ ] **Step 4: main.ts 启动时加载设置**

修改 `storefront/src/main.ts`,在 `app.mount` 前加:
```ts
import { useSettingsStore } from './stores/settings'

const settingsStore = useSettingsStore()
settingsStore.load()
```
(完整文件见 Phase 0 已有,只加这两行到 mount 前。)

- [ ] **Step 5: 提交**

```bash
cd /Users/mac/Project/Php/ZCard
git add storefront/src/ && git commit -m "feat(storefront): api wrappers + settings/products pinia stores"
```

---

## Task 11: 前台列表组件(视图切换器/分类导航/商品卡/热门标签)

**Files:**
- Create: `storefront/src/components/ViewSwitcher.vue`
- Create: `storefront/src/components/CategoryNav.vue`
- Create: `storefront/src/components/ProductCard.vue`
- Create: `storefront/src/components/HotTags.vue`
- Modify: `storefront/src/views/Home.vue`(改造为真实列表)

- [ ] **Step 1: ViewSwitcher.vue**

`storefront/src/components/ViewSwitcher.vue`:
```vue
<script setup lang="ts">
import { useSettingsStore } from '@/stores/settings'
const settings = useSettingsStore()
const views = [
  { key: 'grid', icon: '⊞', label: '网格' },
  { key: 'list', icon: '☰', label: '列表' },
  { key: 'dual', icon: '▦', label: '双栏' },
] as const
</script>

<template>
  <div class="inline-flex border border-gray-200 rounded-lg overflow-hidden">
    <button
      v-for="v in views" :key="v.key"
      @click="settings.setView(v.key)"
      :class="['px-3 py-1.5 text-sm', settings.effectiveView === v.key ? 'bg-primary text-white' : 'bg-white text-gray-500 hover:bg-gray-50']"
      :title="v.label"
    >{{ v.icon }}</button>
  </div>
</template>
```

- [ ] **Step 2: CategoryNav.vue(按 category_nav_style 渲染)**

`storefront/src/components/CategoryNav.vue`:
```vue
<script setup lang="ts">
import { ref, onMounted } from 'vue'
import { getCategories, type Category } from '@/api/categories'

const props = defineProps<{ modelValue: number | null; style: 'pills' | 'sidebar' | 'combo' }>()
const emit = defineEmits<{ (e: 'update:modelValue', v: number | null): void }>()
const cats = ref<Category[]>([])
onMounted(async () => { cats.value = await getCategories() })

function select(id: number | null) { emit('update:modelValue', id) }
</script>

<template>
  <!-- pills: 顶部横排 -->
  <div v-if="style === 'pills'" class="flex flex-wrap gap-2 p-3 border-b bg-white">
    <button @click="select(null)" :class="['pill', modelValue === null ? 'pill-on' : 'pill-off']">全部</button>
    <button v-for="c in cats" :key="c.id" @click="select(c.id)"
      :class="['pill', modelValue === c.id ? 'pill-on' : 'pill-off']">{{ c.name }}</button>
  </div>

  <!-- sidebar: 左侧树 -->
  <div v-else-if="style === 'sidebar'" class="w-44 bg-surface-subtle p-3 border-r">
    <div :class="['cursor-pointer px-2 py-1.5 rounded text-sm', modelValue === null ? 'bg-primary text-white' : 'text-ink-soft']" @click="select(null)">全部商品</div>
    <template v-for="c in cats" :key="c.id">
      <div :class="['cursor-pointer px-2 py-1.5 rounded text-sm mt-0.5', modelValue === c.id ? 'bg-primary text-white' : 'text-ink-soft']" @click="select(c.id)">{{ c.name }}</div>
      <div v-for="ch in c.children" :key="ch.id" :class="['cursor-pointer pl-6 py-1 rounded text-xs', modelValue === ch.id ? 'text-primary font-semibold' : 'text-ink-muted']" @click="select(ch.id)">— {{ ch.name }}</div>
    </template>
  </div>

  <!-- combo: 简化为顶部+pills,同 pills 行为(完整 combo 留优化) -->
  <div v-else class="flex flex-wrap gap-2 p-3 border-b bg-white">
    <button @click="select(null)" :class="['pill', modelValue === null ? 'pill-on' : 'pill-off']">全部</button>
    <button v-for="c in cats" :key="c.id" @click="select(c.id)" :class="['pill', modelValue === c.id ? 'pill-on' : 'pill-off']">{{ c.name }}</button>
  </div>
</template>

<style scoped>
.pill { padding: 4px 14px; border-radius: 16px; font-size: 12px; }
.pill-off { background: #f3f4f6; color: #374151; }
.pill-on { background: var(--color-primary); color: #fff; }
</style>
```

- [ ] **Step 3: ProductCard.vue(支持 grid/list/dual 三态)**

`storefront/src/components/ProductCard.vue`:
```vue
<script setup lang="ts">
import { computed } from 'vue'
import { useRouter } from 'vue-router'
import { useSettingsStore } from '@/stores/settings'
import type { Product } from '@/api/products'

const props = defineProps<{ product: Product }>()
const router = useRouter()
const settings = useSettingsStore()
const view = computed(() => settings.effectiveView)
function go() { router.push(`/product/${props.product.slug}`) }
function fmt(fen: number) { return (fen / 100).toFixed(2) }
</script>

<template>
  <!-- 网格 / 双栏 -->
  <div v-if="view !== 'list'" @click="go"
    :class="['cursor-pointer border border-gray-200 rounded-card bg-white overflow-hidden hover:shadow-md transition', view === 'dual' ? '' : '']">
    <div class="aspect-square bg-gradient-to-br from-blue-100 to-indigo-100 flex items-center justify-center text-primary text-xs">
      <img v-if="product.cover" :src="product.cover" class="w-full h-full object-cover" />
      <span v-else>无图</span>
    </div>
    <div class="p-2">
      <div class="text-xs font-semibold text-ink line-clamp-2 h-8">{{ product.name }}</div>
      <div class="text-primary font-bold mt-1">¥{{ fmt(product.price) }}</div>
      <div v-if="settings.config?.show_stock" class="text-[10px] text-ink-muted">库存 {{ product.stock }}</div>
      <div v-if="settings.config?.show_sales" class="text-[10px] text-ink-muted">已售 {{ product.sales }}</div>
    </div>
  </div>

  <!-- 列表行 -->
  <div v-else @click="go" class="flex gap-3 p-3 border-b border-gray-100 cursor-pointer hover:bg-gray-50 items-center">
    <div class="w-16 h-12 bg-gradient-to-br from-blue-100 to-indigo-100 rounded flex items-center justify-center text-primary text-[9px] flex-shrink-0">
      <img v-if="product.cover" :src="product.cover" class="w-full h-full object-cover rounded" />
      <span v-else>缩略</span>
    </div>
    <div class="flex-1 min-w-0">
      <div class="text-xs font-semibold text-ink truncate">{{ product.name }}</div>
      <div class="text-primary font-bold text-sm">¥{{ fmt(product.price) }}
        <span v-if="settings.config?.show_stock || settings.config?.show_sales" class="text-[10px] text-ink-muted font-normal">
          · <span v-if="settings.config?.show_stock">库存 {{ product.stock }}</span>
          <span v-if="settings.config?.show_sales"> · 已售 {{ product.sales }}</span>
        </span>
      </div>
    </div>
    <button class="bg-primary text-white text-xs px-3 py-1 rounded-field">购买</button>
  </div>
</template>
```

- [ ] **Step 4: HotTags.vue(热门标签云)**

`storefront/src/components/HotTags.vue`:
```vue
<script setup lang="ts">
import { ref, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import { getCategories, type Category } from '@/api/categories'

const props = defineProps<{ ids: number[] }>()
const router = useRouter()
const all = ref<Category[]>([])
onMounted(async () => {
  const tree = await getCategories()
  // 收集所有分类(含子)
  const flat: Category[] = []
  const walk = (list: Category[]) => list.forEach(c => { flat.push(c); c.children && walk(c.children) })
  walk(tree)
  all.value = flat.filter(c => props.ids.includes(c.id))
})
function go() { router.push('/') } // 简化:跳首页(按分类筛留优化)
</script>

<template>
  <div v-if="all.length" class="flex flex-wrap gap-2 py-3">
    <span class="text-[10px] text-ink-muted self-center">热门:</span>
    <span v-for="c in all" :key="c.id" @click="go"
      class="px-3 py-1 bg-primary text-white text-xs rounded-full cursor-pointer">{{ c.name }}</span>
  </div>
</template>
```

- [ ] **Step 5: Home.vue 改造为真实列表**

完全替换 `storefront/src/views/Home.vue`:
```vue
<script setup lang="ts">
import { ref, watch, onMounted } from 'vue'
import { useSettingsStore } from '@/stores/settings'
import { useProductsStore } from '@/stores/products'
import CategoryNav from '@/components/CategoryNav.vue'
import ViewSwitcher from '@/components/ViewSwitcher.vue'
import ProductCard from '@/components/ProductCard.vue'
import HotTags from '@/components/HotTags.vue'

const settings = useSettingsStore()
const products = useProductsStore()
const category = ref<number | null>(null)
const order = ref('')

async function load() {
  await settings.load()
  await products.fetch({
    category: category.value ?? undefined,
    order: order.value || undefined,
  })
}
onMounted(load)
watch(category, load)

const gridClass = (cols: number) => `grid gap-3 p-4 grid-cols-${cols} md:grid-cols-${cols}`
</script>

<template>
  <div>
    <!-- 首页推荐位(简化:暂复用列表,完整轮播留优化) -->
    <!-- 热门标签 -->
    <div class="max-w-6xl mx-auto px-4">
      <HotTags v-if="settings.config?.show_hot_tags" :ids="settings.config?.hot_tag_categories || []" />
    </div>

    <div class="flex max-w-6xl mx-auto">
      <!-- 分类导航 + 列表 -->
      <CategoryNav v-if="settings.config" v-model="category" :style="settings.config.category_nav_style" />
      <div class="flex-1">
        <div class="flex justify-between items-center p-3">
          <span class="text-sm text-ink-soft">全部商品</span>
          <ViewSwitcher />
        </div>
        <div v-if="settings.config?.list_default_view && settings.effectiveView !== 'list'"
          :class="gridClass(settings.config?.grid_columns || 4)">
          <ProductCard v-for="p in products.list" :key="p.id" :product="p" />
        </div>
        <div v-else class="max-w-3xl mx-auto px-4">
          <ProductCard v-for="p in products.list" :key="p.id" :product="p" />
        </div>
        <div v-if="!products.loading && !products.list.length" class="text-center text-ink-muted py-20">
          暂无商品(请先在后台添加商品)
        </div>
      </div>
    </div>
  </div>
</template>
```

- [ ] **Step 6: 跑 dev 验证(需后台先加几个商品)**

```bash
# 后台先建几个商品(浏览器 /admin)
cd /Users/mac/Project/Php/ZCard/storefront && pnpm dev
```
浏览器 :5173 → 应见分类导航(按后台设置样式)+ 商品列表 + 视图切换器生效。`tailwind` 动态 `grid-cols-N` 需 safelist(见 Step 7)。

- [ ] **Step 7: Tailwind v4 safelist 动态列数**

`storefront/src/assets/main.css` 顶部加(确保动态列类被生成):
```css
@source inline("grid-cols-3 grid-cols-4 grid-cols-5");
```

- [ ] **Step 8: 提交**

```bash
cd /Users/mac/Project/Php/ZCard
git add storefront/src/ && git commit -m "feat(storefront): list components (view switcher, category nav, card, hot tags) + Home rewrite"
```

---

## Task 12: 前台商品详情页(大厂电商风格)

**Files:**
- Modify: `storefront/src/views/ProductDetail.vue`(新建,替换占位)
- Modify: `storefront/src/router/index.ts`(路由改 /product/:slug)

- [ ] **Step 1: 新建 ProductDetail.vue**

完全替换 `storefront/src/views/Product.vue`(改名概念:详情页):
```vue
<script setup lang="ts">
import { ref, onMounted, computed } from 'vue'
import { useRoute } from 'vue-router'
import { getProduct, type Product } from '@/api/products'
import { useSettingsStore } from '@/stores/settings'

const route = useRoute()
const settings = useSettingsStore()
const product = ref<Product | null>(null)
const err = ref('')
const selectedSku = ref<number | null>(null)
const qty = ref(1)
const currentImg = ref(0)

onMounted(async () => {
  try {
    product.value = await getProduct(route.params.slug as string)
    selectedSku.value = product.value.skus?.[0]?.id ?? null
  } catch (e) { err.value = '商品不存在' }
})

const price = computed(() => {
  if (!product.value) return 0
  const sku = product.value.skus?.find(s => s.id === selectedSku.value)
  return sku ? sku.price : product.value.price
})
const fmt = (fen: number) => (fen / 100).toFixed(2)
const reviews = computed(() => product.value?.virtual_reviews || {})
function buy() {
  alert(`P1-C 收银台即将开放\n已选: SKU#${selectedSku.value} × ${qty.value}`)
}
</script>

<template>
  <div v-if="err" class="max-w-3xl mx-auto py-20 text-center text-danger">{{ err }}</div>
  <div v-else-if="product" class="max-w-5xl mx-auto px-4 py-6">
    <div class="text-xs text-ink-muted mb-4">首页 / {{ product.category?.name }} / {{ product.name }}</div>

    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
      <!-- 左:配图 -->
      <div>
        <div class="aspect-square rounded-card border bg-gradient-to-br from-blue-100 to-indigo-100 flex items-center justify-center overflow-hidden">
          <img v-if="product.images?.[currentImg]" :src="product.images[currentImg]" class="w-full h-full object-cover" />
          <span v-else class="text-primary">无图</span>
        </div>
        <div class="flex gap-2 mt-2">
          <div v-for="(img, i) in (product.images || [])" :key="i" @click="currentImg = i"
            :class="['w-14 h-14 rounded border-2 cursor-pointer', currentImg === i ? 'border-primary' : 'border-transparent']">
            <img :src="img" class="w-full h-full object-cover rounded" />
          </div>
        </div>
      </div>

      <!-- 右:购买区 -->
      <div>
        <h1 class="text-lg font-bold text-ink leading-snug">{{ product.name }}</h1>
        <div class="text-xs text-ink-muted mt-1">虚拟商品 · 自动发货 · 7×24 小时</div>

        <!-- 促销价格区 -->
        <div class="mt-3 bg-gradient-to-br from-orange-50 to-white border border-orange-200 rounded-card p-4 relative">
          <span class="absolute top-0 right-0 bg-gradient-to-br from-red-500 to-orange-400 text-white text-[9px] font-bold px-3 py-1 rounded-bl-lg">限时</span>
          <div class="flex items-baseline gap-2">
            <span class="text-red-500 font-bold text-sm">¥</span>
            <span class="text-red-500 font-extrabold text-3xl">{{ fmt(price) }}</span>
          </div>
        </div>

        <!-- 评分汇总 -->
        <div class="flex border-t border-b border-gray-100 py-3 my-3 text-center text-xs text-ink-muted">
          <div class="flex-1 border-r border-gray-100"><span class="block text-sm font-bold text-ink">{{ reviews.rating || '—' }}</span>评分</div>
          <div class="flex-1 border-r border-gray-100"><span class="block text-sm font-bold text-ink">{{ reviews.count || 0 }}</span>评价</div>
          <div class="flex-1 border-r border-gray-100"><span class="block text-sm font-bold text-red-500">{{ product.sales }}</span>已售</div>
          <div class="flex-1"><span class="block text-sm font-bold text-ink">{{ product.stock }}</span>库存</div>
        </div>

        <!-- 服务保障 -->
        <div class="flex gap-3 flex-wrap text-[10px] text-ink-soft py-2">
          <span>✓ 自动发货</span><span>✓ 即时到账</span><span>✓ 正品保障</span><span>✓ 售后无忧</span>
        </div>

        <!-- SKU -->
        <div v-if="product.skus?.length" class="mt-4">
          <div class="text-xs font-semibold text-ink-soft mb-2">选择套餐 <span class="text-red-500">*</span></div>
          <div class="flex flex-wrap gap-2">
            <div v-for="s in product.skus" :key="s.id" @click="selectedSku = s.id"
              :class="['relative border-2 rounded-card px-3 py-2 cursor-pointer text-center min-w-[80px]', selectedSku === s.id ? 'border-primary bg-blue-50' : 'border-gray-200']">
              <div :class="['text-xs font-semibold', selectedSku === s.id ? 'text-primary' : 'text-ink-soft']">{{ s.name }}</div>
              <div class="text-xs font-bold text-red-500">¥{{ fmt(s.price) }}</div>
            </div>
          </div>
        </div>

        <!-- 数量 -->
        <div class="mt-4">
          <div class="text-xs font-semibold text-ink-soft mb-2">购买数量</div>
          <div class="inline-flex border border-gray-200 rounded-field overflow-hidden">
            <button @click="qty > 1 && qty--" class="w-9 h-9 text-ink-soft">−</button>
            <input v-model.number="qty" type="number" class="w-14 h-9 text-center font-semibold border-x border-gray-200" />
            <button @click="qty++" class="w-9 h-9 text-ink-soft">+</button>
          </div>
          <span v-if="product.max_order > 0" class="text-[10px] text-ink-muted ml-2">(单次限购 {{ product.max_order }} 件)</span>
        </div>

        <!-- 库存条 -->
        <div class="mt-3" v-if="settings.config?.show_stock">
          <div class="flex justify-between text-[10px] text-ink-muted mb-1"><span>库存充足</span><span>{{ product.stock }} 件</span></div>
          <div class="h-1.5 bg-gray-100 rounded-full overflow-hidden">
            <div class="h-full bg-green-500" :style="{ width: Math.min(product.stock / 600 * 100, 100) + '%' }"></div>
          </div>
        </div>

        <!-- 立即购买 -->
        <button @click="buy" class="w-full mt-4 bg-gradient-to-br from-primary to-blue-500 text-white font-bold py-3 rounded-card shadow-md">立即购买</button>
      </div>
    </div>

    <!-- 商品描述 -->
    <div class="mt-6 border-t-4 border-gray-50 pt-4">
      <h2 class="text-sm font-bold mb-2 border-l-2 border-primary pl-2">商品详情</h2>
      <div class="text-xs text-ink-soft leading-relaxed border rounded-card p-4 bg-white">{{ product.description || '暂无描述' }}</div>
    </div>

    <!-- 虚拟评论(若 show_reviews) -->
    <div v-if="settings.config?.show_reviews && reviews.list?.length" class="mt-4 border-t-4 border-gray-50 pt-4">
      <h2 class="text-sm font-bold mb-2 border-l-2 border-primary pl-2">用户评价</h2>
      <div v-for="(r, i) in reviews.list" :key="i" class="flex gap-2 py-3 border-b border-gray-50 text-xs">
        <div class="w-7 h-7 rounded-full bg-blue-100 text-primary flex items-center justify-center font-bold flex-shrink-0">{{ (r.name || '匿')[0] }}</div>
        <div><div class="font-semibold text-ink">{{ r.name || '匿名用户' }} <span class="text-orange-400">{{ '★'.repeat(r.rating || 5) }}</span></div><div class="text-ink-muted mt-1">{{ r.content }}</div></div>
      </div>
    </div>
  </div>
  <div v-else class="text-center text-ink-muted py-20">加载中…</div>
</template>
```

- [ ] **Step 2: 路由保持 /product/:slug(Phase 0 已有)**

检查 `storefront/src/router/index.ts` 已有 `{ path: 'product/:id', ... component: Product }`。路由名 `:id` 实为 slug,保持不变。

- [ ] **Step 3: 跑 dev 验证详情页**

```bash
cd /Users/mac/Project/Php/ZCard/storefront && pnpm dev
```
浏览器 :5173 → 点商品进详情 → 应见大厂电商风格详情页(促销价/评分/SKU/数量/库存条/立即购买占位)。

- [ ] **Step 4: 提交**

```bash
cd /Users/mac/Project/Php/ZCard
git add storefront/src/ && git commit -m "feat(storefront): product detail page (big-tech e-commerce style)"
```

---

## Task 13: 收尾验证(spec §8 验收清单)

- [ ] **Step 1: 后台能完整管理(建分类+建商品+SKU+设置)**

浏览器后台:
- 建 2 个分类(1 顶级 + 1 子级)
- 建 2 个商品(填全字段、上传图、加 SKU、勾 is_featured、填虚拟销量/评论)
- 进「设置→店铺外观」,改几项配置保存

- [ ] **Step 2: 前台 API 全通**

```bash
curl -s http://localhost:8092/api/categories | head -c 100; echo ""
curl -s "http://localhost:8092/api/products" | head -c 200; echo ""
curl -s http://localhost:8092/api/products/featured | head -c 100; echo ""
curl -s http://localhost:8092/api/settings/storefront | head -c 100
```
Expected: 均返回非空 JSON。

- [ ] **Step 3: 前台列表 + 详情 + 配置联动**

```bash
cd /Users/mac/Project/Php/ZCard/storefront && pnpm dev
```
浏览器 :5173:
- 列表:商品按后台配置渲染(导航样式/视图/列数/库存销量开关)
- 视图切换器:网格/列表/双栏切换,刷新保留
- 详情:点商品进详情,大厂风格,SKU 选择联动价格,立即购买弹占位提示
- 后台改设置 → 刷新前台 → 生效

- [ ] **Step 4: 跑测试**

```bash
./vendor/bin/sail test
```
Expected: PASS(Phase 0 默认测试不破坏)。

- [ ] **Step 5: 确认 docs/ 未进 git**

```bash
git status --short | grep docs || echo "GOOD: docs/ not in git"
```

- [ ] **Step 6: 最终提交(若有未提交改动)**

```bash
git add -A && git status --short
# 若有改动:
git commit -m "chore: P1-A final touches"
```

---

## 完成标准(对照 spec §8)

全部 Task 完成后,逐项核对 spec §8 验收清单(16 项),确认 P1-A 可演示:
- 后台完整管理商品/分类/SKU + 店铺外观配置
- 前台商品列表(配置驱动)+ 大厂风格详情页
- API 全通
- docs/ 未进 git,测试通过
```
