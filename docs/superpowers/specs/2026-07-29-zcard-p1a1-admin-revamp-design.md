# ZCard P1-A.1 — 后台 UI 重塑 + RBAC 设计（Spec）

> P1-A 的收尾增强:后台视觉重塑(Metronic 卡片仪表盘 + 明暗模式 + 可收缩侧栏)+ RBAC 角色权限管理。
> 本文档不进 git（`.gitignore` 忽略整个 `docs/`）。

- **日期**:2026-07-29
- **范围**:P1-A.1(后台 Admin 的 UI 重塑 + RBAC 补齐)
- **状态**:待实现
- **起因**:用户反馈"后台太丑 + 缺 RBAC",参考 acg-faka 后台(Metronic 卡片风 + 深蓝 + 明暗)

---

## 1. 定位与范围

### 1.1 P1-A.1 是什么

P1-A.1 = **后台视觉重塑 + RBAC 补齐**。P1-A 已完成商品/分类管理 + 店铺外观设置,但后台界面仍是 Filament 默认素色、且没有角色管理入口。本子项目把后台打磨到大厂质感,并补齐 RBAC。

### 1.2 范围(最终确认)

**视觉重塑:**
- 主色全局换为亮蓝 `#009EF7`(前后台同步,原 `#2563EB` 作废)
- 自定义仪表盘(Metronic 卡片风:统计卡 + 趋势 + 销售图表 + 公告 + 最近订单)
- 侧边栏可收缩/展开(汉堡按钮,localStorage 记忆)
- 明暗模式切换(右上角按钮,localStorage 记忆,CSS 变量驱动)
- 所有 Resource 设 navigationGroup 分组导航 + 图标

**RBAC:**
- 注册 `FilamentShieldPlugin` → 启用 `RoleResource`(角色管理)
- 角色管理:列表 + 点「编辑权限」弹**居中 Modal** 编辑权限(按 Resource 分组 chip 勾选),super_admin 锁定
- 用户管理:UserResource 表单加角色 Select(下拉切换/分配角色)

**不含:**
- 真实统计数据的计算逻辑(订单/销售额统计留 P1-C,本阶段仪表盘用占位/可获取的简单数据如商品数/用户数)
- 销售图表的真实数据(P1-C 订单就位后接入,本阶段用占位趋势)

---

## 2. 决策记录(来自 brainstorming)

| # | 决策 | 选择 |
|---|---|---|
| D1 | 改造范围 | RBAC + 导航分组 + 仪表盘 + 全面 UI 重塑(全做) |
| D2 | 主色 | 亮蓝 `#009EF7`(acg-faka 同款,前后台同步) |
| D3 | 仪表盘风格 | Metronic 卡片仪表盘风(方向 A) |
| D4 | 仪表盘精致度 | 渐变统计卡 + 图标圆背景 + 趋势涨跌 + 销售柱状图 + 公告位 + 最近订单 |
| D5 | 侧边栏 | 可收缩/展开(汉堡按钮 + localStorage) |
| D6 | 明暗模式 | 右上角切换 + localStorage + CSS 变量驱动 |
| D7 | RBAC 角色/权限 | 用 filament-shield 的 RoleResource |
| D8 | RBAC 流程 | 角色管理(列表+编辑权限)与用户管理(分配角色)**分开**,两个独立菜单项 |
| D9 | 权限编辑容器 | **居中弹窗 Modal**(不塞列表行,点编辑弹 Modal,保存/取消关闭) |
| D10 | super_admin | 锁定(不可编辑权限/删除,走 Gate 自动拥有全部) |

---

## 3. 视觉重塑

### 3.1 主色与主题

- **主色** `#009EF7`(亮蓝),配套 success `#16a34a`、warning `#d97706`、danger `#ef4444`、accent `#00B2FF`(主色渐变伴生色)。
- 在 `AdminPanelProvider` 的 `colors` 配置更新,并在 Filament 自定义主题 CSS(`resources/css/filament/admin/theme.css`)里用 CSS 变量定义,驱动明暗两套色板。
- 前台 storefront 的 `main.css @theme` 同步换主色。

### 3.2 明暗模式(CSS 变量驱动)

- Filament v5 原生支持 `darkMode`,启用后系统级暗色。**额外**用 localStorage 记忆用户手动选择(覆盖系统)。
- 实现思路:在 `AdminPanelProvider` 启用 `->darkMode()`(允许切换),Filament 自带右上角切换按钮;主题 CSS 用 CSS 变量(`--card`, `--bg`, `--text` 等)在 `:root` 与 `.dark` 下各定义一套。
- 用户选择存 localStorage,刷新保留。

### 3.3 可收缩侧边栏

- Filament v5 原生支持 `sidebar collapsible`:`->sidebarCollapsible()`(或对应 v5 配置),汉堡按钮收缩成窄条(只留图标)。
- 收缩状态存 localStorage,Filament 原生记忆。

### 3.4 导航分组

所有 Resource 设 `navigationGroup`:
| 分组 | Resource |
|---|---|
| 商品 | Category, Product(及未来 Card/Sku) |
| 系统 | User, Role(shield), StorefrontSettings |
| (未来)交易 | Order, Payment |

每个 Resource 设 `navigationIcon`(Heroicon)。

---

## 4. 仪表盘(Metronic 卡片风)

### 4.1 自定义 Dashboard

替换 Filament 默认 Dashboard,用 Filament Widgets 组合实现卡片风:

| 区域 | 内容 | 数据来源 |
|---|---|---|
| 统计卡(4) | 商品总数 / 用户总数 / 今日订单(占位0) / 库存预警(占位0) | Product::count() / User::count();订单相关留 P1-C 接入 |
| 销售趋势 | 近 7 日柱状图(占位趋势) | P1-C 订单就位后接真实;本阶段占位 |
| 官方公告 | 公告位(静态/可从 settings 读) | settings 表 `announcement` 占位 |
| 最近订单 | 最近 5 单列表 | P1-C 接入;本阶段空态 |

### 4.2 实现方式

- 用 Filament 自定义 Widgets(StatWidget 自定义卡片 / ChartWidget 柱状图 / TableWidget 最近订单)。
- 卡片视觉:渐变图标圆背景 + 大字数值 + 趋势涨跌 + 阴影,用自定义 Widget 视图(Blade)实现精致度。

---

## 5. RBAC(角色权限)

### 5.1 启用 filament-shield

在 `AdminPanelProvider` 注册插件:
```php
->plugins([
    \BezhanSalleh\FilamentShield\FilamentShieldPlugin::make()
])
```
自动启用 `RoleResource`(路径 `/admin/roles`),含角色 CRUD + 权限编辑。

### 5.2 角色管理(列表 + Modal 编辑权限)

- **列表**:角色表格(名称 / 标识 / 用户数 / 守卫)。super_admin 行操作置灰(锁定)。
- **编辑权限**:点「编辑权限」→ **居中 Modal 弹出**,内含按 Resource 分组的权限 chip 勾选(查看/创建/编辑/删除/恢复...),每组带"全选/反选"。
  - 实现方式:用 Filament 的 `Action::make()->modal()` 或在 RoleResource 的 EditRole 页用 modal 化的权限 Section。
  - super_admin 角色的权限不可编辑(通过 Policy/拦截)。
- 新建角色同理(Modal 或 CreateRole 页)。

### 5.3 用户管理(分配角色)

- `UserResource` 表单加:
```php
Select::make('roles')->relationship('roles', 'name')->multiple()->preload()->label('角色')
```
- 用户表格加 `roles.name` badge 列展示角色。

### 5.4 权限体系

- shield 已为每个 Resource 生成权限(view/view_any/create/update/delete/... 等)。
- super_admin 走 Gate `Before` 自动拥有全部(Phase 0 已配)。
- merchant/user 角色的权限可在角色管理里勾选配置。

---

## 6. 前后台主色同步

| 位置 | 文件 | 改动 |
|---|---|---|
| 后台 | `app/Providers/Filament/AdminPanelProvider.php` | colors primary 改 `#009EF7` |
| 后台 | `resources/css/filament/admin/theme.css` | 加 CSS 变量 + 明暗色板 |
| 前台 | `storefront/src/assets/main.css` | `@theme --color-primary: #009EF7` |

---

## 7. P1-A.1 验收清单

- [ ] 主色全局换 `#009EF7`(前后台)
- [ ] 明暗模式切换(右上角,localStorage 记忆)
- [ ] 侧边栏可收缩/展开(汉堡按钮,记忆)
- [ ] 所有 Resource 设 navigationGroup + 图标(分组清晰)
- [ ] 自定义仪表盘(4 统计卡 + 销售趋势图 + 公告 + 最近订单,占位数据 OK)
- [ ] 注册 FilamentShieldPlugin,`/admin/roles` 可访问
- [ ] 角色管理列表 + 点编辑弹居中 Modal 编辑权限
- [ ] super_admin 角色锁定(不可编辑/删除)
- [ ] UserResource 表单可分配角色 + 表格显示角色 badge
- [ ] 后台整体视觉接近 acg-faka Metronic 质感
- [ ] git commit 粒度合理,docs/ 不进 git

---

## 8. 风险与对策

| 风险 | 对策 |
|---|---|
| Filament v5 仪表盘 Widgets API 与 v4 差异 | 用 v5 的 Widget 基类,实现时以官方文档为准 |
| 明暗模式 CSS 变量与 Filament 内置 darkMode 协作 | 优先用 Filament 原生 darkMode + 主题 CSS 覆盖;避免双重实现 |
| 仪表盘统计数据 P1-C 才有 | 本阶段商品数/用户数真实,订单/销售占位,P1-C 接入 |
| PHP 8.5 联合类型命名空间陷阱(Phase 0 踩过) | 新代码涉及 UnitEnum/BackedEnum 全局前缀 `\` |

---

## 9. Open Questions(无)

brainstorming 阶段所有决策已确认(§2)。无遗留。

---

*本 spec 为活文档,实现中如有偏差回填。*
