# ZCard P1-A.1 — 后台 UI 重塑 + RBAC 实现计划

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** 把后台从 Filament 默认素色打磨到 Metronic 卡片仪表盘质感(亮蓝 #009EF7 + 明暗切换 + 可收缩侧栏 + 分组导航),并补齐 RBAC 角色权限管理。

**Architecture:** 用 Filament v5 原生能力为主:`sidebarCollapsibleOnDesktop()` / `darkMode()` / `StatsOverviewWidget` / 自定义 Dashboard 页;注册 `FilamentShieldPlugin` 启用角色管理;所有 navigation 用 getter 方法(规避 PHP 8.5 联合类型陷阱)。

**Tech Stack:** Laravel 13, Filament v5.7.3, bezhansalleh/filament-shield v4.3.1, Tailwind v4 主题 CSS。

**对应 spec:** `docs/superpowers/specs/2026-07-29-zcard-p1a1-admin-revamp-design.md`

**v5 API 已确认(关键纠正):**
- 统计卡用 `StatsOverviewWidget` + `Stat`(无 `StatWidget`)
- 侧栏收缩用 `sidebarCollapsibleOnDesktop()`(无 `sidebarCollapsible`)
- 明暗:`->darkMode(false)` 会关掉,删掉这行(默认 true,自动渲染切换按钮,localStorage 记忆)
- shield 角色路径默认 `/admin/shield/roles`(非 /admin/roles)
- **权限编辑容器调整(重要):** shield 的 EditRole 用页面钩子同步权限,Modal EditAction 跑不了这些钩子 → **改用 shield 原生完整编辑页**(分 entity 标签页,UI 已分组清晰)。spec D9 的"Modal"调整为此。理由:可靠性 > 视觉偏好。
- navigation 用 getter 方法(`getNavigationGroup()` 等),不用属性(规避 PHP 8.5 类型陷阱)

---

## 环境前提

- 容器在跑(`./vendor/bin/sail up -d`),app :8092,管理后台 :8092/admin。
- P1-A 已完成:商品/分类/店铺外观设置。
- 所有 artisan 命令走 `./vendor/bin/sail`(简写 `sail`)。

---

## 文件结构总览

```
app/Providers/Filament/AdminPanelProvider.php   # T1,T2,T3 改
app/Filament/
├── Pages/Dashboard.php                          # T4 自定义仪表盘
├── Widgets/
│   ├── ShopStatsWidget.php                      # T4 统计卡
│   ├── SalesChartWidget.php                     # T4 销售趋势
│   └── LatestOrdersWidget.php                   # T4 最近订单(空态)
├── Resources/
│   ├── Categories/CategoryResource.php          # T5 nav
│   ├── Products/ProductResource.php             # T5 nav
│   ├── Users/UserResource.php (+ Schema)        # T6 加角色 Select + nav
│   └── (Merchants/UserResource nav)             # T5
config/filament-shield.php                        # T7 publish
resources/css/filament/admin/theme.css           # T8 主题 CSS(已存在,扩展)
storefront/src/assets/main.css                   # T9 主色同步
```

---

## Task 1: 主色换亮蓝 + 启用明暗 + 可收缩侧栏

**Files:**
- Modify: `app/Providers/Filament/AdminPanelProvider.php`

- [ ] **Step 1: 读当前 AdminPanelProvider 的 colors/darkMode 段**

```bash
grep -n "colors\|darkMode\|brandName\|sidebarCollapsible" app/Providers/Filament/AdminPanelProvider.php
```

- [ ] **Step 2: 修改 colors(主色换 #009EF7),删除 darkMode(false),加 sidebarCollapsibleOnDesktop**

修改 `app/Providers/Filament/AdminPanelProvider.php`:

把 `->colors([...])` 块改为:
```php
->colors([
    'primary' => '#009EF7',
    'success' => '#16a34a',
    'warning' => '#d97706',
    'danger' => '#ef4444',
])
```

**删除** `->darkMode(false)` 这一行(它在 Task4 of Phase0 加的)。删除后 Filament 默认启用 darkMode(true),右上角自动渲染 Light/Dark/System 三按钮切换器,localStorage 记忆。

在 `->colors([...])` 之后、`->brandName(...)` 之后加:
```php
->sidebarCollapsibleOnDesktop()
```

- [ ] **Step 3: 验证后台可访问 + 明暗切换器出现**

```bash
./vendor/bin/sail artisan optimize:clear
curl -s -o /dev/null -w "%{http_code}\n" http://localhost:8092/admin
```
Expected: 302(登录页)。浏览器登录后:右上角应有明暗切换按钮(☀️/🌙/🖥),侧栏有汉堡收缩按钮,主色为亮蓝。

- [ ] **Step 4: 提交**

```bash
git add app/Providers/Filament/AdminPanelProvider.php && git commit -m "feat(admin): switch primary to #009EF7, enable dark mode + collapsible sidebar"
```

---

## Task 2: 注册 FilamentShieldPlugin(启用角色管理)

**Files:**
- Modify: `app/Providers/Filament/AdminPanelProvider.php`

- [ ] **Step 1: 在 AdminPanelProvider 注册 shield 插件**

修改 `app/Providers/Filament/AdminPanelProvider.php`,在 `->discoverResources(...)` 之前加(或任意合适位置):
```php
->plugins([
    \BezhanSalleh\FilamentShield\FilamentShieldPlugin::make()
        ->navigationGroup('系统')
        ->navigationIcon('heroicon-o-shield-check')
        ->navigationLabel('角色权限'),
])
```

- [ ] **Step 2: 验证 shield 角色管理可访问**

```bash
./vendor/bin/sail artisan optimize:clear
curl -s -o /dev/null -w "%{http_code}\n" http://localhost:8092/admin/shield/roles
```
Expected: 302(需登录)。浏览器登录后台 → 左侧「系统」分组下应有「角色权限」,点进去见角色列表(super_admin / merchant / user)。

- [ ] **Step 3: 提交**

```bash
git add app/Providers/Filament/AdminPanelProvider.php && git commit -m "feat(admin): register filament-shield plugin for role management"
```

---

## Task 3: 所有 Resource 设导航分组(用 getter 方法)

**Files:**
- Modify: `app/Filament/Resources/Categories/CategoryResource.php`
- Modify: `app/Filament/Resources/Products/ProductResource.php`
- Modify: `app/Filament/Resources/Merchants/MerchantResource.php`
- Modify: `app/Filament/Resources/Users/UserResource.php`

> **用 getter 方法,不用属性**(规避 PHP 8.5 `string|UnitEnum|null` 类型陷阱)。每个 Resource 加两个静态方法。

- [ ] **Step 1: CategoryResource 加导航**

修改 `app/Filament/Resources/Categories/CategoryResource.php`,在 class 内加:
```php
public static function getNavigationGroup(): string | \UnitEnum | null
{
    return '商品';
}

public static function getNavigationIcon(): string | \BackedEnum | null
{
    return 'heroicon-o-tag';
}
```

- [ ] **Step 2: ProductResource 加导航**

修改 `app/Filament/Resources/Products/ProductResource.php`,加:
```php
public static function getNavigationGroup(): string | \UnitEnum | null
{
    return '商品';
}

public static function getNavigationIcon(): string | \BackedEnum | null
{
    return 'heroicon-o-shopping-bag';
}
```

- [ ] **Step 3: MerchantResource 加导航**

修改 `app/Filament/Resources/Merchants/MerchantResource.php`,加:
```php
public static function getNavigationGroup(): string | \UnitEnum | null
{
    return '系统';
}

public static function getNavigationIcon(): string | \BackedEnum | null
{
    return 'heroicon-o-building-storefront';
}
```

- [ ] **Step 4: UserResource 加导航**

修改 `app/Filament/Resources/Users/UserResource.php`,加:
```php
public static function getNavigationGroup(): string | \UnitEnum | null
{
    return '系统';
}

public static function getNavigationIcon(): string | \BackedEnum | null
{
    return 'heroicon-o-users';
}
```

- [ ] **Step 5: 验证后台导航分组**

```bash
./vendor/bin/sail artisan optimize:clear
```
浏览器登录后台 → 左侧应见分组:【商品】(商品管理、分类管理)、【系统】(用户管理、商户管理、角色权限、店铺外观)。

- [ ] **Step 6: 提交**

```bash
git add app/Filament/Resources/ && git commit -m "feat(admin): group resources by navigation (商品/系统) with icons"
```

---

## Task 4: 自定义仪表盘 + 统计卡 + 销售图表

**Files:**
- Create: `app/Filament/Pages/Dashboard.php`
- Create: `app/Filament/Widgets/ShopStatsWidget.php`
- Create: `app/Filament/Widgets/SalesChartWidget.php`
- Create: `app/Filament/Widgets/LatestOrdersWidget.php`

- [ ] **Step 1: 创建 ShopStatsWidget(统计卡)**

`app/Filament/Widgets/ShopStatsWidget.php`:
```php
<?php

namespace App\Filament\Widgets;

use App\Models\Product;
use App\Models\User;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class ShopStatsWidget extends StatsOverviewWidget
{
    protected function getStats(): array
    {
        return [
            Stat::make('商品总数', Product::count())
                ->description('全部商品')
                ->descriptionIcon('heroicon-m-shopping-bag')
                ->color('primary')
                ->icon('heroicon-o-shopping-bag'),
            Stat::make('用户总数', User::count())
                ->description('注册用户')
                ->descriptionIcon('heroicon-m-users')
                ->color('success')
                ->icon('heroicon-o-users'),
            Stat::make('今日订单', 0)
                ->description('P1-C 接入')
                ->descriptionIcon('heroicon-m-clipboard-document-list')
                ->color('warning')
                ->icon('heroicon-o-clipboard-document-list'),
            Stat::make('库存预警', 0)
                ->description('P1-B 接入')
                ->descriptionIcon('heroicon-m-exclamation-triangle')
                ->color('danger')
                ->icon('heroicon-o-exclamation-triangle'),
        ];
    }

    protected static ?int $sort = 1;
}
```

- [ ] **Step 2: 创建 SalesChartWidget(销售趋势柱状图,占位数据)**

`app/Filament/Widgets/SalesChartWidget.php`:
```php
<?php

namespace App\Filament\Widgets;

use Filament\Widgets\ChartWidget;

class SalesChartWidget extends ChartWidget
{
    protected static ?string $heading = '近 7 日销售趋势';

    protected static ?int $sort = 2;

    protected function getData(): array
    {
        // 占位数据,P1-C 订单就位后接真实销售额
        return [
            'datasets' => [
                [
                    'label' => '销售额(元)',
                    'data' => [125.8, 262.5, 138.0, 375.2, 255.0, 488.8, 370.0],
                    'backgroundColor' => '#009EF7',
                    'borderRadius' => 6,
                ],
            ],
            'labels' => ['周一', '周二', '周三', '周四', '周五', '周六', '今天'],
        ];
    }

    protected function getType(): string
    {
        return 'bar';
    }
}
```

- [ ] **Step 3: 创建 LatestOrdersWidget(最近订单,空态)**

`app/Filament/Widgets/LatestOrdersWidget.php`:
```php
<?php

namespace App\Filament\Widgets;

use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;

class LatestOrdersWidget extends TableWidget
{
    protected static ?string $heading = '最近订单';

    protected static ?int $sort = 3;

    public function table(Table $table): Table
    {
        // P1-C 订单模型就位后切换为 Order::query()->latest()->limit(5)
        return $table
            ->query(fn (): Builder => \App\Models\Product::query()->whereRaw('1=0')) // 空态占位
            ->emptyStateHeading('暂无订单')
            ->emptyStateDescription('订单数据将在 P1-C(订单核心)完成后显示')
            ->columns([
                TextColumn::make('id')->label('订单号'),
            ]);
    }
}
```

- [ ] **Step 4: 创建自定义 Dashboard 页**

`app/Filament/Pages/Dashboard.php`:
```php
<?php

namespace App\Filament\Pages;

use App\Filament\Widgets\LatestOrdersWidget;
use App\Filament\Widgets\SalesChartWidget;
use App\Filament\Widgets\ShopStatsWidget;
use Filament\Pages\Dashboard as BaseDashboard;

class Dashboard extends BaseDashboard
{
    public function getWidgets(): array
    {
        return [
            ShopStatsWidget::class,
            SalesChartWidget::class,
            LatestOrdersWidget::class,
        ];
    }

    public function getColumns(): int | array
    {
        return [
            'default' => 1,
            'md' => 2,
        ];
    }
}
```

- [ ] **Step 5: 移除默认 widgets(避免 FilamentInfoWidget 等占位)**

修改 `app/Providers/Filament/AdminPanelProvider.php`,把 `->widgets([AccountWidget::class, FilamentInfoWidget::class])` 改为只留 AccountWidget:
```php
->widgets([
    \Filament\Widgets\AccountWidget::class,
])
```
(移除 `FilamentInfoWidget::class` 及其 use,保留 AccountWidget)

- [ ] **Step 6: 验证仪表盘**

```bash
./vendor/bin/sail artisan optimize:clear
```
浏览器进后台首页(仪表盘)→ 应见:4 个统计卡(商品总数/用户总数为真实数字,今日订单/库存预警为 0)+ 销售柱状图 + 最近订单空态。

- [ ] **Step 7: 提交**

```bash
git add app/Filament/ && git commit -m "feat(admin): custom dashboard with stats/chart/orders widgets"
```

---

## Task 5: UserResource 加角色分配 + 显示角色 badge

**Files:**
- Modify: `app/Filament/Resources/Users/Schemas/UserForm.php`
- Modify: `app/Filament/Resources/Users/Tables/UsersTable.php`

- [ ] **Step 1: UserForm 加角色 Select**

修改 `app/Filament/Resources/Users/Schemas/UserForm.php`,在 components 数组里加(末尾):
```php
use Filament\Forms\Components\Select;
// ... 在 components 末尾加:
Select::make('roles')
    ->relationship('roles', 'name')
    ->multiple()
    ->preload()
    ->label('角色'),
```

- [ ] **Step 2: UsersTable 加角色 badge 列**

修改 `app/Filament/Resources/Users/Tables/UsersTable.php`,在 columns 里加:
```php
use Filament\Tables\Columns\TextColumn;
// ... 在 columns 加:
TextColumn::make('roles.name')->badge()->label('角色')->color('primary'),
```

- [ ] **Step 3: 验证用户管理可分配角色**

```bash
./vendor/bin/sail artisan optimize:clear
```
浏览器后台 → 用户管理 → 编辑某用户 → 表单应有「角色」多选 → 表格应显示角色 badge。

- [ ] **Step 4: 提交**

```bash
git add app/Filament/Resources/Users/ && git commit -m "feat(admin): assign roles in UserResource (select + badge column)"
```

---

## Task 6: 店铺外观设置页归到「系统」分组

**Files:**
- Modify: `app/Filament/Pages/StorefrontSettings.php`

- [ ] **Step 1: StorefrontSettings 导航分组确认/调整**

读 `app/Filament/Pages/StorefrontSettings.php`,确认 `getNavigationGroup()` 返回 `'系统'`。若不是,改为:
```php
public static function getNavigationGroup(): string | \UnitEnum | null
{
    return '系统';
}
```
(Phase 0 已设,确认即可)

- [ ] **Step 2: 验证设置页在「系统」分组下**

浏览器后台 → 左侧「系统」分组 → 店铺外观 应在该分组下。

- [ ] **Step 3: 提交(若有改动)**

```bash
git status --short
# 若有改动:
git add app/Filament/Pages/StorefrontSettings.php && git commit -m "chore(admin): ensure StorefrontSettings in 系统 group"
```

---

## Task 7: shield 配置发布 + 角色路径优化(可选)

**Files:**
- Create: `config/filament-shield.php`

> shield 默认角色路径 `/admin/shield/roles`。若想改成 `/admin/roles`,发布配置。**可选,不改也能用**。

- [ ] **Step 1: 发布 shield 配置**

```bash
./vendor/bin/sail artisan vendor:publish --tag="filament-shield-config"
```

- [ ] **Step 2: 改 slug 为 roles**

打开 `config/filament-shield.php`,找到 `'slug' => 'shield/roles'`,改为:
```php
'slug' => 'roles',
```

- [ ] **Step 3: 验证**

```bash
./vendor/bin/sail artisan optimize:clear
curl -s -o /dev/null -w "%{http_code}\n" http://localhost:8092/admin/roles
```
Expected: 302。浏览器后台角色管理路径应为 `/admin/roles`。

- [ ] **Step 4: 提交**

```bash
git add config/filament-shield.php && git commit -m "chore(admin): publish shield config, roles at /admin/roles"
```

---

## Task 8: 后台主题 CSS(CSS 变量,明暗色板打磨)

**Files:**
- Modify: `resources/css/filament/admin/theme.css`

> 主色已由 `->colors(['primary'=>'#009EF7'])` 控制。主题 CSS 用于微调(圆角/阴影/卡片),非必需。本 Task 做轻量打磨。

- [ ] **Step 1: 扩展主题 CSS**

修改 `resources/css/filament/admin/theme.css`(Phase 0 已存在占位),追加:
```css
/*
 * ZCard Filament 主题微调。
 * 主色 #009EF7 已由 AdminPanelProvider colors 配置驱动。
 * 此处做卡片圆角/阴影等轻量打磨。
 */

/* 统计卡圆角与阴影增强 */
.fi-widget {
    border-radius: 12px;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.06);
}

/* 侧栏导航项圆角 */
.fi-sidebar-nav-item {
    border-radius: 8px;
}
```

- [ ] **Step 2: 验证无破坏**

```bash
curl -s -o /dev/null -w "%{http_code}\n" http://localhost:8092/admin
```
Expected: 302。后台正常(主题 CSS 仅微调,不影响功能)。

- [ ] **Step 3: 提交**

```bash
git add resources/css/filament/admin/theme.css && git commit -m "style(admin): polish theme css (card radius, shadows)"
```

---

## Task 9: 前台主色同步 #009EF7

**Files:**
- Modify: `storefront/src/assets/main.css`

- [ ] **Step 1: 前台主色 token 换 #009EF7**

修改 `storefront/src/assets/main.css`,把 `--color-primary` 改:
```css
@theme {
  --color-primary: #009EF7;
  /* 其余不变 */
}
```
(原 `#2563EB` 改为 `#009EF7`)

- [ ] **Step 2: 验证前台构建**

```bash
cd /Users/mac/Project/Php/ZCard/storefront && pnpm run build 2>&1 | tail -3
```
Expected: built 成功。

- [ ] **Step 3: 提交**

```bash
cd /Users/mac/Project/Php/ZCard
git add storefront/src/assets/main.css && git commit -m "style(storefront): sync primary color to #009EF7"
```

---

## Task 10: 收尾验证(spec §7 验收清单)

- [ ] **Step 1: 视觉验收(浏览器)**

```bash
# 确保容器在跑
docker ps --filter name=zcard --format '{{.Names}}: {{.Status}}'
```
浏览器后台(http://localhost:8092/admin):
- 主色亮蓝 #009EF7(按钮/链接/激活态)
- 右上角明暗切换(☀️/🌙/🖥),切换后刷新保留
- 侧栏汉堡按钮可收缩(收成图标条)
- 导航分组:【商品】【系统】清晰
- 仪表盘:4 统计卡 + 销售柱状图 + 最近订单空态

- [ ] **Step 2: RBAC 验收**

浏览器后台:
- 「系统 → 角色权限」可访问,见 super_admin/merchant/user
- 点某角色「编辑」→ 进入权限编辑页(shield 原生,分 entity 标签页,按 Resource 分组勾选)
- super_admin 编辑/删除按钮:验证锁定(若未锁,记录为已知项,P1 后续优化)
- 「系统 → 用户管理」→ 编辑用户 → 可分配角色;表格显示角色 badge

- [ ] **Step 3: 测试通过**

```bash
./vendor/bin/sail test 2>&1 | tail -3
```
Expected: PASS。

- [ ] **Step 4: docs 未进 git**

```bash
git ls-files docs/ | head -1 && echo "BAD" || echo "GOOD: docs not tracked"
```

- [ ] **Step 5: 最终提交(若有未提交)**

```bash
git status --short
# 有改动则提交
```

---

## 完成标准(对照 spec §7)

全部 Task 完成后核对:
- 主色 #009EF7 全局(前后台)✓
- 明暗切换 + 侧栏收缩 ✓
- 导航分组 ✓
- 自定义仪表盘 ✓
- shield 角色管理 ✓
- 用户分配角色 ✓
- 后台整体 Metronic 质感 ✓(统计卡/图表/分组/明暗)

---

## 与 spec 的偏差(诚实记录)

| spec 项 | 计划做法 | 原因 |
|---|---|---|
| D9 权限编辑用居中 Modal | 改用 shield 原生完整编辑页 | shield 的 EditRole 用页面钩子同步权限,Modal EditAction 跑不了这些钩子,不可靠。原生页分 entity 标签页已分组清晰,可靠性优先 |
| 仪表盘统计真实订单数据 | 今日订单/库存预警占位 0 | 订单系统 P1-C 才有,本阶段用占位 |
| 主题 CSS 重写 | 仅轻量微调 | 主色由 colors 配置驱动,主题 CSS 非必需 |
