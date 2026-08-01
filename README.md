<p align="center">
  <a href="#">
    <img src="public/favicon.ico" width="120" height="120" style="border-radius: 20px;" alt="ZCard">
  </a>
</p>

<h1 align="center">ZCard — 现代化自动发卡 / 虚拟商品销售系统</h1>

<p align="center">
  <strong>Laravel 13 · Vue 3 · Filament v5 · Element Plus · 多货币 · 多语言 · 三级分销 · 分站 · API-First</strong>
</p>

<p align="center">
<span><img src="https://img.shields.io/badge/PHP-8.3+-777BB4?logo=php&logoColor=white" alt="PHP 8.3+"></span>
<span><img src="https://img.shields.io/badge/Laravel-13-FF2D20?logo=laravel&logoColor=white" alt="Laravel 13"></span>
<span><img src="https://img.shields.io/badge/Vue-3-42B883?logo=vue.js&logoColor=white" alt="Vue 3"></span>
<span><img src="https://img.shields.io/badge/MySQL-8.0+-4479A1?logo=mysql&logoColor=white" alt="MySQL 8.0+"></span>
<span><img src="https://img.shields.io/badge/Redis-6.0+-DC382D?logo=redis&logoColor=white" alt="Redis"></span>
<span><img src="https://img.shields.io/badge/License-MIT-green" alt="MIT"></span>
</p>

---

## 法律声明

> 本程序基于 MIT 协议开源，完全免费，初衷是为开发者提供学习与研究机会。未取得合法资质，严禁将本程序用于任何商业用途，尤其是禁止利用本程序搭建平台进行商品销售。
>
> 使用本程序即表示您已充分理解并同意本法律声明的所有内容。

---

## 环境要求

| 组件 | 版本要求 | 说明 |
|---|---|---|
| **PHP** | **>= 8.3** | 需扩展：pdo_mysql, mbstring, openssl, bcmath, json, curl, gd(或 imagick), redis(可选) |
| **MySQL** | **>= 8.0**（推荐 8.4） | utf8mb4 字符集 |
| **Redis** | **>= 6.0**（可选） | 用于缓存，不装也可运行（降级为 database 缓存） |
| **Composer** | >= 2.x | PHP 依赖管理 |
| **Node.js** | >= 18（可选） | 仅开发/重新编译前端时需要，**生产部署不需要**（编译产物已在仓库） |

> **生产部署无需 Node.js/pnpm** —— `public/admin/` 和 `public/storefront/` 的编译产物已提交到仓库，`git clone` 后可直接访问。

---

## 安装方式

ZCard 提供两种安装方式，选择其中一种即可。

### 方式一：Web 安装向导（推荐）

适合不熟悉命令行的用户，浏览器操作即可完成。

```bash
# 1. 克隆仓库 + 安装 PHP 依赖
git clone https://github.com/NovaWorks/ZCard.git
cd ZCard
composer install

# 2. 配置环境
cp .env.example .env

# 3. 浏览器访问安装向导
# 访问 http://你的域名/install
# 按向导步骤操作：
#   Step 1: 环境检查（自动检测 PHP 扩展 + 目录权限）
#   Step 2: 填写数据库信息（支持实时连接测试）
#   Step 3: 设置管理员邮箱 + 密码
#   Step 4: 安装完成，跳转后台
```

### 方式二：命令行安装

适合服务器运维人员，一行命令完成。

```bash
# 1. 克隆仓库 + 安装依赖
git clone https://github.com/NovaWorks/ZCard.git
cd ZCard
composer install

# 2. 配置环境
cp .env.example .env
php artisan key:generate

# 3. 交互式安装（会提示输入数据库和管理员信息）
php artisan zcard:install
#    或跳过数据库交互（使用已有 .env 配置）:
php artisan zcard:install --skip-db --email=admin@example.com --password=yourpassword

# 4. 完成！
# 访问 http://你的域名        → 前台商城
# 访问 http://你的域名/admin   → 后台管理
```

### Docker 开发环境（Laravel Sail）

```bash
composer install
./vendor/bin/sail up -d
./vendor/bin/sail artisan zcard:install --skip-db
```

### 默认端口

| 服务 | 地址 |
|---|---|
| 前台商城 | http://你的域名/ |
| 管理后台 | http://你的域名/admin/ |
| 安装向导 | http://你的域名/install |
| Filament 面板（开发期 CRUD） | http://你的域名/filament |

---

## 功能总览

### 🛒 商品与卡密

- **商品管理**：分类（树形+图标+排序拖拽）、多 SKU、会员等级定价、最低/最高起购、限购、精选/热标签、虚拟销量与虚拟评价、自定义购买控件
- **卡密系统**：应用层 **AES 加密**存储 + sha256 去重（去重可按商品开关）；批量导入（≤5000 同步、>5000 入队列），导入批次可撤销
- **库存防超卖**：下单时 `lockForUpdate` 行锁，付款失败/订单超时自动释放

### 💳 订单与支付

- **自动发卡**：付款成功即刻发货（同步事务），支持"标记已用/物理删除"两种模式，邮件+短信通知
- **游客下单**：无需注册，订单查询支持订单号/联系方式 + 查询密码
- **9 大支付通道**（全部真实实现）：

  | 通道 | 支持货币 |
  |---|---|
  | 支付宝 / 微信支付 | CNY |
  | PayPal | USD/EUR/GBP |
  | Stripe | USD/EUR/GBP/CNY/JPY |
  | 易支付 / 码支付 | CNY |
  | USDT (TRC20) | USDT |
  | EpuSdt | CNY/USD |

### 💰 多货币

- 基础货币（默认 CNY）为唯一记账真相源，所有金额以「分」整数存储
- 客户可切换显示货币（前台切换器），按汇率实时换算
- 下单瞬间锁定汇率快照，历史订单不随后续汇率变动

### 🌐 多语言

- 前台 vue-i18n 中/英双语，即时切换
- 后端 API `__()` 全量提取（`Accept-Language`/`X-Lang` 头控制）
- 后台可配置启用的语言与默认语言

### 👥 三级分销

- 推广链接注册绑定上下级链（`?ref=用户名`）
- 按毛利 × 每级费率向上追溯最多 3 级发佣
- 自推荐拒绝、自购拦截
- FIFO 提现 + 后台审批

### 🏪 分站 / 白标店铺

- 域名解析（Host 头 + Redis 缓存 + 归一化）
- 4 模式加价定价引擎（inherit / markup_percent / fixed_markup / fixed_price）
- 订单快照 + 冻结期账本 + 防自购（owner + buyer 上级链）
- DNS TXT + HTTP well-known 双方案域名验证
- FIFO 提现 + 后台审批
- 与分销互斥

### 🎟️ 优惠券

- 满减券 / 折扣券，限定商品/分类，最低消费门槛，批量生成

### 📊 仪表盘

- ECharts 销售趋势面积图 + 订单柱状图
- 8 个 KPI 指标卡（订单/收入/利润/支付/库存/用户/提现）
- 热销商品 Top10 + 支付通道排行

### 🔄 在线更新

- Git-based 更新（检查 GitHub Release → 一键 git pull + migrate + 重建前端）
- 版本回退（git reset + migrate:rollback）
- 维护模式 + 版本锁防并发

### 🔒 安全

- RBAC 权限守卫（super_admin / merchant / user）
- AES-256 卡密加密存储
- 支付回调凭据校验 + 金额核对 + 幂等
- 认证端点限流（登录/注册 5次/分）
- 图形验证码

### 📱 通知

- 邮件通知（SMTP，发货通知 + 验证码）
- 短信通知（阿里云 REST API，发货通知 + 验证码）

### ⭐ 评价审核

- 评价审核系统（pending/approved/rejected）
- 真实评价 + 虚拟评价合并评分

---

## 技术栈

| 层 | 技术 |
|---|---|
| **后端** | PHP 8.3+ · Laravel 13 · Filament v5 · Sanctum · Spatie Permission · yansongda/pay · stripe-php · mews/captcha · bcmath |
| **前台 storefront** | Vue 3 · Vite · Tailwind CSS v4 · Pinia · Vue Router · vue-i18n · axios |
| **后台 sysadmin** | Vue 3 · Element Plus · ECharts · Pinia · Vue Router · Tailwind v4 |
| **基础设施** | MySQL 8.0+ · Redis 6.0+（可选） · Laravel Sail (Docker) |

---

## 项目结构

```
ZCard/
├── app/
│   ├── Console/Commands/          zcard:install 安装命令
│   ├── Filament/                  Filament v5 后台
│   ├── Http/Controllers/Api/      API 控制器（Admin + 前台 + 安装向导）
│   ├── Http/Middleware/           中间件（显示货币/语言/维护模式/RBAC/分站解析）
│   ├── Models/                    数据模型
│   ├── Payment/                   支付契约 + 9 个驱动
│   └── Support/                   业务服务（CurrencyService/OrderService/CommissionService 等）
├── database/migrations/           数据库迁移
├── lang/                          后端多语言（zh_CN / en）
├── public/admin/                  后台编译产物（已在仓库）
├── public/storefront/             前台编译产物（已在仓库）
├── storefront/                    前台源码（Vue 3 + Tailwind）
├── sysadmin/                      后台源码（Vue 3 + Element Plus）
├── config/zcard.php               功能开关
└── compose.yaml                   Laravel Sail 编排
```

---

## 常用命令

| 命令 | 说明 |
|---|---|
| `php artisan zcard:install` | 交互式安装（提示输入数据库+管理员） |
| `php artisan zcard:install --skip-db` | 跳过数据库交互（使用已有 .env） |
| `php artisan migrate` | 运行数据库迁移 |
| `php artisan test` | 运行测试（56 passed） |
| `php artisan tinker` | Tinker REPL |
| `cd storefront && pnpm build` | 重新编译前台（开发时） |
| `cd sysadmin && pnpm build` | 重新编译后台（开发时） |

---

## 版本号规则

采用 `x.y.z` 版本号：

- **x**（重大版本）：核心大量重构或破坏性 API 变更
- **y**（主要功能）：公开 API 破坏性变更，可能不兼容前版本
- **z**（修复版本）：BUG 修复/安全修复/新增不破坏兼容的功能

---

## 路线图

### ✅ 已完成
- 商品/卡密/SKU/库存防超卖
- 9 大支付通道
- 多货币 + 多语言
- 三级分销 + 分站/白标店铺
- 优惠券 / 仪表盘 / RBAC / 短信 / 评价审核
- 在线更新（Git-based）+ 版本回退
- Web 安装向导 + 命令行交互式安装

### 🚧 规划中
- 多商户（config flag 已预留）
- 更多支付通道
- 应用商店/插件系统

---

## 更多支持

- **Telegram 群组**：[@ZhonCard](https://t.me/ZhonCard)
- **Telegram 频道**：[@ZCardGroup](https://t.me/ZCardGroup)

---

*开源协议：MIT。本项目仅供学习研究，请遵守当地法律法规。*
