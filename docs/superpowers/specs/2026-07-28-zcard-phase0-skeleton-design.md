# ZCard Phase 0 — 代码骨架设计（Spec）

> 基于 `acg-faka/开发计划.md`，初始化 ZCard 项目（异次发卡 / 现代化插件制虚拟发卡系统）的 Phase 0 地基。
> 本文档为 brainstorming 产出，不进 git（`.gitignore` 忽略整个 `docs/`）。

- **日期**：2026-07-28
- **范围**：Phase 0（地基）—— 能登录的后台 + 空前台工程 + Docker 开发环境 + 核心数据库 schema + RBAC 初始化
- **状态**：待实现

---

## 1. 背景与目标

### 1.1 项目定位

ZCard 是一款基于 Laravel 的现代化、插件制虚拟发卡 / 个人店铺系统，以"开源引流 + 商业版变现"为核心商业模式，以"现代技术栈 + 真插件系统 + 极致插件开发体验"为核心差异化。参考项目 acg-faka（真插件 + 老栈）与 dujiao-next（现代栈 + 无真插件），ZCard 切入"真插件 + 现代体验 + 低门槛"这个三角缺口。

### 1.2 Phase 0 目标（本 spec 范围）

按计划 Phase 0「地基（1~2 周）」交付：

1. Laravel 13 + PHP 8.3 项目初始化
2. Filament 后台骨架 + 主题定制（按用户设计图配色）
3. 核心数据库设计（用户、商品、卡密、订单、商户、配置）
4. 接入 spatie/laravel-permission + filament-shield，初始化角色（super_admin / merchant / user）与基础权限
5. 基础登录、用户/商户 CRUD
6. 前台 Vue3 工程初始化 + 请求封装 + 路由

**交付物**：能登录的后台 + 空前台工程 + 可运行的开发环境。

### 1.3 明确不做（留给后续 Phase）

- 商品/卡密/订单的业务逻辑与后台 Resource（Phase 1）
- 插件系统的 Hook 总线、安装/启停生命周期（Phase 2）—— Phase 0 仅放骨架占位
- 支付通道、优惠券、分销、分站、会员等级、报表（Phase 1~4）
- 真正的插件加载运行（Phase 0 的 example-plugin 仅占位，不会被加载）

---

## 2. 决策记录

本 spec 的关键决策来自 brainstorming 阶段的用户确认：

| # | 决策 | 选择 |
|---|---|---|
| D1 | 骨架范围 | **Phase 0 完整版**：地基 + 核心 schema + RBAC + 基础 CRUD |
| D2 | 开发环境 | **Docker (Laravel Sail)**：本机仅 Node/pnpm/Docker，PHP/MySQL/Redis 走容器 |
| D3 | 数据库 | **MySQL 8** |
| D4 | 前台组织 | **独立 Vue3 工程**（`storefront/` 子目录），pnpm 管理 |
| D5 | 示例插件 | **放骨架占位**（`plugins/example-plugin/`），为 Phase 2 预热 |
| D6 | git 提交粒度 | **首提交只含骨架**；`.gitignore` 忽略整个 `docs/` |
| D7 | 初始化方式 | **artisan 命令 `zcard:install`** 交互式初始化，随机生成 8 位密码，首次登录强制改密 |
| D8 | 强制改密 | 用 `users.password_changed_at` 字段 + 中间件实现 |
| D9 | 主题 | 按用户设计图：浅色 + 主色蓝 `#2563EB` |

---

## 3. 技术栈与依赖

### 3.1 版本锁定

| 层 | 选型 | 版本 | 依据 |
|---|---|---|---|
| 运行时 | PHP | 8.3+ | Laravel 13 最低要求 |
| 包管理（后端） | Composer | 2.x | — |
| 框架 | Laravel | 13.x | 2026-03 发布，无破坏性变更，AI-native |
| 后台 | Filament | v5.x（≈5.7） | Laravel 13 原生支持，最新稳定 |
| RBAC | spatie/laravel-permission | v8 | Laravel 13 兼容 |
| RBAC UI | bezhansalleh/filament-shield | 兼容 Filament v5 | 自动生成权限/Policy/管理界面 |
| 数据库 | MySQL | 8 | D3 |
| 缓存/队列 | Redis | Sail 自带 | 单机可降级文件缓存 |
| 开发环境 | Laravel Sail | Docker | D2 |
| 前台框架 | Vue | 3.x（`<script setup>` + TS） | — |
| 前台构建 | Vite | 5.x | — |
| 前台样式 | Tailwind CSS | v4 | 复用后台设计 token |
| 前台状态 | Pinia | 2.x | — |
| 前台路由 | Vue Router | 4.x | — |
| 前台 HTTP | axios | 1.x | — |
| 包管理（前端） | pnpm | 本机已有 | — |

### 3.2 运行约定

- 后端走 Sail 容器（`./vendor/bin/sail up`），本机不需要装 PHP/MySQL/Redis。
- 前台 `storefront/` 独立 `pnpm install`，Vite dev server 在 `:5173`，proxy `/api` 到 Laravel。
- Laravel 自带的 `resources/js` + `vite.config.js` **保留但不用**（后台用 Filament 的 Livewire），避免删了之后 Filament 资源编译出问题。

### 3.3 Open Core 预留

Phase 0 为后续 Open Core（单一主干 + 功能开关）预留结构，但不在 Phase 0 实现功能锁。预留方式：`config/zcard.php` 放功能开关占位（如 `features.multi_merchant`、`features.distribution`），默认全 `false`，代码里不消费。

---

## 4. 项目布局

monorepo，单 git 仓。

```
ZCard/
├── app/
│   ├── Console/Commands/
│   │   └── InstallCommand.php          # zcard:install（§7）
│   ├── Filament/
│   │   └── Resources/
│   │       ├── UserResource.php        # §8
│   │       └── MerchantResource.php
│   ├── Http/
│   │   ├── Middleware/
│   │   │   └── ForcePasswordChange.php # §7
│   │   └── Controllers/Api/
│   │       └── HealthController.php    # /api/health 前台联调用
│   ├── Models/                         # §6
│   │   ├── User.php, Merchant.php, MerchantMember.php
│   │   ├── Category.php, Product.php, Card.php
│   │   ├── Order.php, OrderItem.php, Payment.php
│   │   └── Setting.php
│   ├── Providers/
│   │   ├── Filament/AdminPanelProvider.php
│   │   └── AppServiceProvider.php      # 注册插件系统占位
│   └── ...
├── database/
│   ├── migrations/                     # §6 全部表
│   └── seeders/DatabaseSeeder.php      # 演示数据，不建账号
├── plugins/
│   └── example-plugin/                 # 骨架占位（计划 §3.2）
│       ├── plugin.json
│       ├── src/ServiceProvider.php     # 空，仅示范
│       └── README.md
├── storefront/                         # 独立 Vue3 工程（§9）
│   ├── src/
│   │   ├── api/ (request.ts, health.ts)
│   │   ├── router/, stores/, layouts/, views/, components/
│   │   ├── assets/main.css
│   │   └── App.vue, main.ts
│   ├── package.json, vite.config.ts, tailwind.config.js, tsconfig.json
├── docker-compose.yml                  # Laravel Sail
├── .env.example                        # 含 DB/Redis/ADMIN_EMAIL
├── docs/                               # 忽略，不进 git
└── README.md                           # 快速启动说明
```

---

## 5. 设计 Token（主题）

来源：用户提供的后台设计图。**浅色专业风格**。

| Token | 值 | 用途 |
|---|---|---|
| primary 主色 | `#2563EB`（蓝） | 按钮、链接、激活态、品牌色 |
| success | `#10B981`（绿） | 成功/增长类指标 |
| warning | `#F59E0B`（橙） | 警告/重要指标 |
| danger | `#EF4444`（红） | 危险/错误 |
| 紫色点缀 | `#8B5CF6` | 数据装饰图标 |
| 主背景 | `#FFFFFF` | 浅色模式 |
| 卡片背景 | `#F9FAFB` | 内容区 |
| 主文字 | `#111827` / `#374151` | 标题 / 正文 |
| 辅助文字 | `#6B7280` | 描述小字 |
| 圆角 | `8px`（卡片/按钮）、`4px`（输入框） | 现代柔和 |
| 阴影 | 轻微 `0 1px 3px rgba(0,0,0,0.1)` | 轻层次 |
| 模式 | **浅色（Light）** | 锁定，不跟随系统暗色 |

**共享**：这套 token 后台（Filament 主题）与前台（storefront Tailwind 配置）共用，保证视觉统一。

---

## 6. 核心数据库 Schema

### 6.1 设计决策

1. **`merchant_id` 全局冗余**：每张业务表带 `merchant_id`，即使 MVP 单店也写死 `merchant_id=1`。为 Phase 3 多商户零迁移铺路。
2. **金额用整数分**（`amount INT`，单位分），不用 decimal/float——避免浮点误差。
3. **`cards.content` 应用层加密 + `content_hash` 明文 hash**：**不**用 Laravel `encrypted` cast（其随机 IV 使每次密文不同、无法建索引/去重）。改为应用层 AES 批量加密存密文，另存 `content_hash`（sha256 明文）用于去重与索引。安全性：DB 被拖库后密文不可解（密钥在 .env），hash 单向不可逆。**这是大量卡密导入（10 万~百万级）能否跑通的关键设计。**
   - **去重范围 = 产品内**：唯一约束是 `UNIQUE(product_id, content_hash)` 组合键。语义:**同一产品内卡密不可重复**(防止重复导入/重复发同一张);**不同产品之间允许卡密相同**(同一串兑换码可能是不同商品的合法库存)。例:产品 A 已有卡密 `ABC123`,产品 B 也导入 `ABC123` → 允许并存;但产品 A 再导入一个 `ABC123` → 被唯一索引拒绝。`(product_id, status)` 复合索引加速库存查询与发货。
4. **卡密导入批次追溯**：新增 `card_imports` 表，`cards.import_id` 关联。支持批次统计（成功/失败数）、失败明细、撤回某次导入（删该 import_id 下所有 unused 卡密）。Phase 0 建表，Phase 1 实现导入逻辑。
5. **卡密锁定超时释放**：`cards.status` 加 `disabled`（人工停用）态，新增 `locked_at`。发货时锁卡，订单超时未支付则按 `locked_at` 释放回 unused——高并发发卡必备。
6. **`products.member_price` 用 json**：会员等级未定型，json 比每等级一列灵活。
7. **`products.control_config` json**：对应"自定义控件"（购买时填邮箱/账号等），Phase 0 留字段。
8. **soft deletes**：users/products/orders 用；cards/card_imports/settings/categories 不用。
9. **`order_items` 预留**：MVP 一单一商品，但建表，Phase 1 写下单逻辑时不用先迁移。**移除原 `order_items.card_ids`**——统一用 `cards.order_id` 反查某订单的卡密，单一数据源避免双写不一致。
10. **多商户唯一键**：`merchants.slug`、`products.slug`、`categories.slug` 用 `(merchant_id, slug)` 复合唯一，避免多商户下 slug 冲突及软删除唯一键冲突。
11. **精度明确**：`merchants.commission_rate` 用 `decimal(5,4)`（0.0000~9.9999）。
12. **卡密发放模式（店主在后台二选一，默认 2）**：每个产品可选择支付成功后卡密的处理方式，由 `products.delivery_mode` 字段控制：
    - **`status`（默认）**：支付成功后将发货卡密在 `cards` 表置为 `used`(设 `used_at`、`order_id`)并写一份明文快照到 `order_deliveries`,**库存表行保留**。适合"兑换码/可重复使用的凭证"——卡密仍有审计/复用价值。
    - **`delete`**：支付成功后将发货卡密的明文写一份快照到 `order_deliveries`,**然后从 `cards` 库存表物理删除该行**。适合"一次性独占凭证/激活码"——卖出即销毁,杜绝库存表泄密面。
    - **为何两种模式都强制写 `order_deliveries` 快照**:模式 1 删库后,客户"我的订单/退款/客服查单"必须有据可查;快照表与库存表解耦,客户视图永远读快照表,不被发放模式影响。
13. **发货快照表 `order_deliveries`（库存与客户视图解耦）**：支付成功时按所选发放模式写入,客户在前台"我的订单"看到的卡密来自此表,而非 `cards` 库存表。`cards.order_id` 仅作库存表内部审计关联(模式 1 删除后该关联自然消失,符合预期)。

### 6.2 表清单（按依赖顺序）

#### 身份与权限

spatie 自带：`roles`, `permissions`, `model_has_roles`, `model_has_permissions`, `role_has_permissions`（包迁移自动生成）。

| 表 | 关键字段 |
|---|---|
| `users` | id, username, email, email_verified_at, password, status, balance(余额，分), password_changed_at(nullable), last_login_at, timestamps + soft deletes |
| `merchants` | id, user_id(店主), name, slug, status, commission_rate(**decimal(5,4)**), settings(json), timestamps + soft deletes；**UNIQUE(slug)** |
| `merchant_members` | id, merchant_id, user_id, role, timestamps |

#### 商品与库存

| 表 | 关键字段 |
|---|---|
| `categories` | id, parent_id(树形), merchant_id, name, slug, sort, status, timestamps；**UNIQUE(merchant_id, slug)** |
| `products` | id, merchant_id, category_id(nullable), name, slug, description(longText), price(分), member_price(json), cover, images(json), stock_type(enum: card/url/code), stock_visible(bool), control_config(json), **delivery_mode(enum: status/delete, 默认 status)**, sort, status, timestamps + soft deletes；**UNIQUE(merchant_id, slug)** |
| `cards` | id, product_id, **import_id**(nullable, FK→card_imports), **content**(应用层加密密文), **content_hash**(sha256 明文), status(enum: **unused/locked/used/disabled**), order_id(nullable), **locked_at**(nullable), used_at(nullable), timestamps；索引：**UNIQUE(product_id, content_hash)**(产品内去重,跨产品允许重复)、**INDEX(product_id, status)**、INDEX(order_id)、INDEX(import_id) |
| `card_imports` | id, product_id, operator_id, source(文件名/来源), total(文件总行数), success_count, failed_count, status(enum: running/completed/failed), error_log(nullable, json, 失败明细), timestamps |

#### 订单

| 表 | 关键字段 |
|---|---|
| `orders` | id, order_no(unique), merchant_id, user_id(nullable, 游客), product_id, quantity, amount(分), status(enum: pending/paid/closed/refunded), paid_at, closed_at, contact, extra(json), timestamps |
| `order_items` | id, order_id, product_id, amount(分), timestamps（**无 card_ids**，卡密经 `cards.order_id` 反查） |
| `payments` | id, order_id, channel, channel_order_no, amount(分), status(enum: pending/success/failed), paid_at, raw(json 回调原文), timestamps |
| `order_deliveries` | id, order_id, product_id, card_content(明文,客户最终看到的卡密), delivered_mode(enum: status/delete, 实际使用的发放模式), delivered_at, timestamps；索引：INDEX(order_id)、INDEX(product_id)。**说明**:支付成功时写入(发放快照),客户"我的订单"读此表而非 `cards`。无论 products.delivery_mode 选哪种,本表都保留,作为客户视图/退款/查单的唯一凭证。 |

#### 系统

| 表 | 关键字段 |
|---|---|
| `settings` | id, key(unique), value(json), group, timestamps |

### 6.3 不做

优惠券、秒杀、分销、分站、报表、会员等级表（Phase 1~4）。

---

## 7. RBAC 与初始化命令

### 7.1 角色定义

| 角色 slug | 中文 | 权限范围 | team |
|---|---|---|---|
| `super_admin` | 超级管理员 | 全部（filament-shield 自动授予） | 全局 |
| `merchant` | 商户/店主 | 自己商户内的商品/卡密/订单/配置 + 店员管理 | 按商户隔离 |
| `user` | 会员/顾客 | 前台自助（查自己订单、个人资料），**不进 Filament 后台** | — |

### 7.2 artisan 命令 `zcard:install`

**首次部署唯一入口**，命令行交互式完成系统初始化。替代"Seeder 写死账号"。

行为规则：

1. **幂等**：检测到已存在 super_admin 账号时，跳过创建并提示，可重复安全执行。
2. **随机密码**：`Str::random(8)`（8 位），明文只在命令行打印一次，DB 存 bcrypt hash。**不写进 .env、不写日志**。
3. **邮箱可参数化**：`php artisan zcard:install --email=you@example.com`，默认 `admin@zcard.local`。
4. **步骤**：生成 APP_KEY → migrate → 创建角色权限 → 创建默认商户（merchant_id=1, slug=default）→ 创建 super_admin 账号 → 打印随机密码。
5. **Seeder 调整**：`DatabaseSeeder` 只 seed 演示数据（生产不跑），**不再创建账号**。账号唯一来源是 `zcard:install`。

### 7.3 首次登录强制改密

- `users.password_changed_at`（nullable）：命令创建的账号该字段为 null。
- `app/Http/Middleware/ForcePasswordChange.php`：super_admin/merchant 角色登录后若该字段为 null → 强制跳转改密页；改密后写入当前时间。
- 挂在 Filament 的 authMiddleware。

### 7.4 入口隔离

- 后台：`/admin`（Filament，Livewire + Tailwind）。
- 前台 API：`/api/*`（给 Vue storefront 用）。
- 顾客（`user` 角色）访问 `/admin` → 403（Filament Policy 拦截）。

### 7.5 Teams 预留

spatie 的 teams 功能 Phase 0 **开启配置但暂不强用**——商户隔离先用 `merchant_id` 字段过滤（§6 已铺），Teams 留给 Phase 3 多店。配置开启避免后续返工。

---

## 8. Filament 后台

### 8.1 Panels

只建一个 panel：**`AdminPanelProvider`**（Filament 默认，路径 `/admin`）。Phase 0 不做商户独立 panel。

### 8.2 Resources（Phase 0 两个，做示范）

| Resource | 对应表 | CRUD 字段 |
|---|---|---|
| `UserResource` | users | 用户名、邮箱、状态、余额、角色分配 |
| `MerchantResource` | merchants | 名称、slug、状态、店主 |

其余表（products/cards/orders/...）的 Resource Phase 0 不建，留给 Phase 1。每建一个 Resource 跑 `php artisan shield:generate` 自动产出权限 + Policy。

### 8.3 主题落地（Filament v5）

1. 配色：在 `AdminPanelProvider` 把 primary 设为 `#2563EB`，配套 success/warning/danger（§5）。
2. 品牌名：`brand('ZCard')`。
3. Logo：登录页 + 侧边栏放占位 Logo（SVG 放 `public/`）。
4. 浅色模式：锁定 `mode = light`。
5. 自定义主题层：保留 `resources/css/filament/admin/theme.css` 入口，后续微调用。

---

## 9. 前台 Vue3 工程（`storefront/`）

### 9.1 技术栈

Vue 3（`<script setup>` + TS）+ Vite 5 + Tailwind v4 + Pinia + Vue Router 4 + axios。UI 库暂不引入（用 Tailwind 手写，计划要求"惊艳"）。

### 9.2 Phase 0 交付

工程能 `pnpm dev` 跑起来，含：

- 路由骨架：首页 `/`、占位商品页 `/product/:id`、收银台占位 `/checkout`、登录注册 `/login` `/register`。
- 全局布局：Header + Footer + 主内容区。
- axios 请求封装（`src/api/request.ts`）：baseURL `/api`、token 拦截器、错误处理；调通 `/api/health`。
- Tailwind + §5 设计 token 配置好。
- 一张占位首页：展示配色落地（验收视觉用）。

### 9.3 不做

真实商品数据、下单逻辑、支付（Phase 1）。

---

## 10. 开发环境（Laravel Sail）

- `docker-compose.yml` 由 Sail 生成，含 PHP 8.3 + MySQL 8 + Redis。
- `./vendor/bin/sail up -d` 起容器。
- 前台 `storefront/` 在宿主机用 pnpm 跑（不需要进容器），Vite proxy 到容器 Laravel `:80`。
- `.env.example` 含：`DB_CONNECTION=mysql`、`DB_HOST=mysql`、`REDIS_HOST=redis`、`ADMIN_EMAIL`、`SANCTUM_STATEFUL_DOMAINS=localhost:5173`、**`CARD_ENCRYPTION_KEY`**（卡密应用层加密密钥，32 字节 base64，`php artisan key:generate` 风格生成，`zcard:install` 时生成）。

---

## 11. Phase 0 验收清单

- [ ] `./vendor/bin/sail up` 一键起容器（PHP8.3 + MySQL8 + Redis）
- [ ] `php artisan migrate` 成功，12 张业务表 + 5 张 spatie 表就位（业务表：users, merchants, merchant_members, categories, products, cards, card_imports, orders, order_items, payments, order_deliveries, settings）
- [ ] `php artisan zcard:install` 交互式初始化，生成随机 8 位密码
- [ ] 首次登录强制改密（`password_changed_at` 机制生效）
- [ ] `/admin` 能登录，看到 User/Merchant 两个 Resource，主题是设计图蓝 `#2563EB`
- [ ] `/api/health` 返回 JSON，前台 axios 能调通
- [ ] `storefront/` `pnpm dev` 起来，首页展示设计图配色
- [ ] `plugins/example-plugin/` 骨架存在，结构清晰
- [ ] 首个 git commit 只含骨架（`.gitignore` 忽略 `docs/`）

---

## 12. 风险与对策

| 风险 | 对策 |
|---|---|
| Filament v5 + Laravel 13 兼容 | 均已确认原生支持；用 Filament v5.x 最新稳定版 |
| spatie teams 与 filament-shield 协作 | Phase 0 仅开配置不强用；用 `merchant_id` 字段过滤隔离 |
| 强制改密中间件与 Filament 集成 | 挂在 Filament `authMiddleware`；Filament v5 支持自定义中间件 |
| 前后台跨域 | Vue dev `:5173` → Laravel `:80`，用 Vite proxy + Laravel sanctum/CORS 配置；.env 留 `SANCTUM_STATEFUL_DOMAINS` |
| 插件占位误导 | README 明确标注"Phase 2 才生效，当前不加载" |
| Sail 首次起镜像慢 | README 注明首次 `sail up -d` 可能拉镜像几分钟 |
| docs 误提交 git | `.gitignore` 写 `docs/`，首提交只含骨架 |

---

## 13. Open Questions（无）

brainstorming 阶段所有关键决策已确认（§2）。无遗留问题。

---

*本 spec 为活文档，实现过程中如有偏差回填更新。*
