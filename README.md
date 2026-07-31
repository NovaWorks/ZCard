<p align="center">
  <a href="#">
    <img src="public/favicon.ico" width="120" height="120" style="border-radius: 20px;" alt="ZCard">
  </a>
</p>

<h1 align="center">ZCard — 现代化自动发卡 / 虚拟商品销售系统</h1>

<p align="center">
  <strong>Laravel 13 · Vue 3 · Filament v5 · Element Plus · 多货币 · 多语言 · API-First</strong>
</p>

<p align="center">
<span>
<img src="https://img.shields.io/badge/PHP-8.3+-777BB4?logo=php&logoColor=white" alt="PHP 8.3+">
</span>
<span>
<img src="https://img.shields.io/badge/Laravel-13-FF2D20?logo=laravel&logoColor=white" alt="Laravel 13">
</span>
<span>
<img src="https://img.shields.io/badge/Vue-3-42B883?logo=vue.js&logoColor=white" alt="Vue 3">
</span>
<span>
<img src="https://img.shields.io/badge/MySQL-8.4-4479A1?logo=mysql&logoColor=white" alt="MySQL">
</span>
<span>
<img src="https://img.shields.io/badge/License-MIT-green" alt="MIT">
</span>
</p>

---

## 法律声明

> 本程序基于 MIT 协议开源，完全免费，初衷是为开发者提供学习与研究机会。未取得合法资质，严禁将本程序用于任何商业用途，尤其是禁止利用本程序搭建平台进行商品销售。
>
> 使用本程序即表示您已充分理解并同意本法律声明的所有内容。

---

## 项目简介

ZCard 是一套**现代化的虚拟商品自动发卡 / 个人店铺系统**，采用 Laravel 13 + Vue 3 双栈构建，开箱即用。

**核心差异化**：现代技术栈（PHP 8.3 / Laravel 13 / Vue 3 / Tailwind v4）+ 多货币 + 多语言 + API-First 架构 + 双后台（Element Plus SPA + Filament v5）+ 卡密应用层加密 + 8 大支付通道。

> 适用场景：游戏卡密 / 账号售卖、软件授权、会员开通、数字商品自动交付、点卡兑换码等任何"付款即自动发货"的在线交易。

---

## 功能简介

### 🛒 商品与卡密

- **商品管理**：支持分类（树形 + 图标 + 排序拖拽）、多 SKU、会员等级定价、最低/最高起购、限购、精选/热标签、虚拟销量与虚拟评价、自定义购买控件（control_config）。
- **卡密系统**：应用层 **AES 加密**存储 + sha256 去重（去重可按商品开关）；批量导入（≤5000 同步、>5000 入队列），导入批次可撤销；支持预选卡密加价、卡类型（月卡/周卡等）。
- **库存防超卖**：下单时 `lockForUpdate` 行锁锁定卡密，付款失败/订单超时自动释放。

### 💳 订单与支付

- **自动发卡**：付款成功即刻触发发货（同步事务），支持"标记已用 / 物理删除"两种发货模式，可邮件通知。
- **游客下单**：无需注册即可购买；订单查询支持按订单号/联系方式 + 查询密码。
- **8 大支付通道**（全部真实实现，非占位）：

  | 通道 | 支持货币 | 说明 |
  |---|---|---|
  | 支付宝 / 微信支付 | CNY | yansongda/pay v3 |
  | PayPal | USD/EUR/GBP | PayPal Orders v2 |
  | Stripe | USD/EUR/GBP/CNY/JPY | Checkout Session + Webhook |
  | 易支付 / 码支付 | CNY | 通用聚合支付 |
  | USDT | USDT | TRC20 钱包二维码 |
  | EpuSdt | CNY/USD | EpUSDT / GMPay |

  每个通道可在后台独立配置凭据、手续费、排序、启停；回调统一做凭据校验（防空 key 伪造）+ 金额核对 + 幂等。

### 💰 多货币

- **基础货币**（默认 CNY）为唯一记账真相源，所有金额以「分」整数存储（bcmath 运算，杜绝浮点误差）。
- **客户可切换显示货币**：前台货币切换器，按汇率实时换算展示，下单瞬间**锁定汇率快照**（历史订单不随后续汇率变动）。
- 后台货币管理：增删货币、改汇率、设基础货币、启停（改汇率自动清缓存）。

### 🌐 多语言

- **前台 storefront**：vue-i18n，中/英双语，语言切换器即时切换（无需刷新），记住上次选择。
- **后端 API**：`Accept-Language` / `X-Lang` 请求头控制，错误提示消息多语言。
- 后台可配置启用的语言与默认语言。

### 👤 会员与资金

- **会员体系**：用户 + 会员等级（user_groups，自定义折扣率、充值升级阈值），商品可按等级差异化定价。
- **余额 / 账单**：每笔资金变动记流水（balance_after 快照），后台可手动调账。
- **提现**：支持支付宝/微信/USDT 提现，手续费配置，审批流（批准/驳回退回）。
- **认证**：注册/登录/找回密码（邮箱验证码），首次登录强制改密，图形验证码可配。

### 🎟️ 优惠券

- 满减券（固定金额）/ 折扣券（百分比），可限定商品/分类，最低消费门槛，批量生成，下单前可预校验。

### 🛠️ 双后台管理

- **Element Plus SPA（`/admin`）**：基于 art-design-pro 模板，现代化管理后台，覆盖商品/分类/卡密/订单/会员/用户/账单/提现/优惠券/支付通道/货币/设置全模块，中英双语，ECharts 数据看板。
- **Filament v5 面板（`/filament`）**：开发期 CRUD 利器，配合 filament-shield 的 RBAC（super_admin / merchant / user 三角色）。

### ⚙️ 店铺配置（~60 项）

站点信息、页脚（链接/客服/社交）、列表布局、注册开关、验证码、维护模式、SMTP 邮件、提现、多货币、多语言……全部后台可视化配置。

### 🔒 安全

卡密 AES 加密存储 · 支付回调凭据校验 + 金额核对 + 幂等 · 首次登录强制改密 · RBAC 权限模型 · 维护模式开关。

---

## 技术栈

| 层 | 技术 |
|---|---|
| **后端** | PHP 8.3+ · Laravel 13 · Filament v5 · Sanctum（API Token）· spatie/laravel-permission + filament-shield（RBAC）· yansongda/pay · stripe-php · mews/captcha · bcmath |
| **前台 storefront** | Vue 3 · Vite · Tailwind CSS v4 · Pinia · Vue Router · vue-i18n · axios |
| **后台 sysadmin** | Vue 3 · Element Plus · Vite · Pinia · Vue Router · ECharts · wangEditor · Tailwind v4 |
| **基础设施** | MySQL 8.4 · Redis · Laravel Sail（Docker）|

---

## 快速开始

### 环境要求

- Docker（PHP/MySQL/Redis 走容器，无需本机安装）
- Node 22 + pnpm（前端构建）

### 安装步骤

```bash
# 1. 克隆仓库
git clone https://github.com/NovaWorks/ZCard.git
cd ZCard

# 2. 安装后端依赖
composer install

# 3. 启动容器（首次构建镜像约 1-3 分钟）
./vendor/bin/sail up -d

# 4. 初始化系统（迁移 + RBAC + 默认商户 + 超管账号，幂等）
./vendor/bin/sail artisan zcard:install
#    命令行会打印一个随机初始密码，首次登录强制改密

# 5. 前端
cd storefront && pnpm install && pnpm dev      # 前台，访问 http://localhost:5173
cd ../sysadmin && pnpm install && pnpm dev      # 后台 SPA dev（:3006），或用构建产物
```

### 访问地址

| 服务 | 地址 |
|---|---|
| Laravel 应用 | http://localhost:8092 |
| **管理后台 SPA** | **http://localhost:8092/admin/** |
| Filament 面板（开发期 CRUD） | http://localhost:8092/filament |
| 前台商城（dev） | http://localhost:5173 |
| MySQL | localhost:3307 |
| Redis | localhost:6380 |

> 超管账号：`admin@zcard.local` + install 打印的密码。端口可在 `.env` 的 `APP_PORT` / `FORWARD_DB_PORT` / `FORWARD_REDIS_PORT` 调整。

---

## 项目结构

```
ZCard/
├── app/
│   ├── Console/Commands/InstallCommand.php   zcard:install 初始化
│   ├── Filament/                             Filament v5 后台（Resources/Pages/Widgets）
│   ├── Http/Controllers/Api/                 API 控制器（Admin + 前台）
│   ├── Http/Middleware/                      中间件（显示货币/语言/维护模式/强制改密）
│   ├── Models/                               数据模型
│   ├── Payment/                              支付契约 + 8 个驱动
│   └── Support/                              业务服务（CurrencyService/OrderService/PaymentService 等）
├── database/migrations/                      数据库迁移
├── lang/                                     后端多语言（zh_CN / en）
├── storefront/                               Vue3 前台商城（独立工程）
├── sysadmin/                                 Vue3 + Element Plus 管理后台（构建到 public/admin/）
├── plugins/                                  插件骨架（规划中，见下文）
├── config/zcard.php                          功能开关
└── compose.yaml                              Laravel Sail 编排
```

---

## 常用命令

| 命令 | 说明 |
|---|---|
| `./vendor/bin/sail up -d` | 启动容器 |
| `./vendor/bin/sail artisan zcard:install` | 系统初始化（幂等） |
| `./vendor/bin/sail artisan test` | 运行测试 |
| `./vendor/bin/sail artisan tinker` | Tinker REPL |
| `cd storefront && pnpm dev` / `pnpm build` | 前台 dev / 构建 |
| `cd sysadmin && pnpm dev` / `pnpm build` | 后台 dev / 构建到 `public/admin/` |

---

## 路线图

### ✅ 已完成

- **Phase 1**：商品管理、卡密导入与库存、订单与收银台、8 大支付通道、自动发货、会员/余额/提现、优惠券、认证与 RBAC、双后台。
- **多货币多语言**：客户可切换显示货币 + 订单汇率快照 + 支付通道货币元数据 + 前后端 i18n（中/英）。

### 🚧 规划中

- **插件系统**：Hook 总线 + 插件安装/启停生命周期（当前 `plugins/` 仅为骨架）。
- **多商户 / 三级分销 / 分站**：当前为单商户运营（`config/zcard.php` 已预留开关，Phase 3+ 启用）。
- **余额支付**：余额账户已就绪，下单时用余额抵扣的流程待接入。
- **更多语言**：后台框架级英文翻译补全。

---

## 更多支持

- **Telegram 群组**：[@ZhonCard](https://t.me/ZhonCard)
- **Telegram 频道**：[@ZCardGroup](https://t.me/ZCardGroup)

---

*开源协议：MIT。本项目仅供学习研究，请遵守当地法律法规。*
