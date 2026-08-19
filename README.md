<p align="center">
  <a href="#">
    <img src="public/logo.png" width="120" height="120" style="border-radius: 20px;" alt="ZCard">
  </a>
</p>

<h1 align="center">ZCard — 双向上下游自动发卡 / 虚拟商品销售系统</h1>

<p align="center">
  <strong>既能对接 ACG-Faka、Dujiao Next、其他 ZCard 自动拿货，也能开放供货 API 向下游供货</strong>
</p>

<p align="center">
  Laravel 13 · Vue 3 · 双向货源 · 自动发卡 · 多货币 · 多语言 · 三级分销 · 分站 · API-First
</p>

<p align="center">
<span><img src="https://img.shields.io/badge/PHP-8.3+-777BB4?logo=php&logoColor=white" alt="PHP 8.3+"></span>
<span><img src="https://img.shields.io/badge/Laravel-13-FF2D20?logo=laravel&logoColor=white" alt="Laravel 13"></span>
<span><img src="https://img.shields.io/badge/Vue-3-42B883?logo=vue.js&logoColor=white" alt="Vue 3"></span>
<span><img src="https://img.shields.io/badge/MySQL-8.0+-4479A1?logo=mysql&logoColor=white" alt="MySQL 8.0+"></span>
<span><img src="https://img.shields.io/badge/Redis-6.0+-DC382D?logo=redis&logoColor=white" alt="Redis"></span>
<a href="LICENSE"><img src="https://img.shields.io/badge/License-Apache%202.0-green" alt="Apache License 2.0"></a>
</p>

<p align="center">
  <em>Open-source automatic card vending, digital goods storefront and bidirectional upstream/downstream supply platform.</em>
</p>

---

## 核心差异：双向上下游货源网络

ZCard 不只是一个面向顾客的发卡商城。它同时实现了**下游拿货端**和**上游供货端**，可以把多个发卡系统串成自动同步、自动下单、自动交付的供货网络。

```text
ACG-Faka / Dujiao Next / 另一套 ZCard
                    ↓ 商品同步、自动拿货
              ZCard（作为下游）
                    ↓ 本站零售，也可继续开放供货
              ZCard（作为上游）
                    ↓ HMAC 供货 API
              其他 ZCard / 下游系统
```

| 角色 | 已实现能力 |
|---|---|
| **作为下游拿货** | 可同时配置多个上游货源；兼容 **ACG-Faka（异次元发卡）**、**Dujiao Next（独角数卡 Next）** 和 **ZCard**；支持连接测试、商品预览/勾选导入、分类映射、全量/增量同步、定时采集、价格同步、上下架同步、库存同步与本地自由定价；顾客付款后可同步拿货，失败自动转队列重试。 |
| **作为上游供货** | 提供完整的 `/api/supply/*` 供货 API；支持下游账号申请/审核、`api_key + api_secret`、预存余额、充值与调整账本、商品级/SKU 级专属供货价、商品/库存查询、幂等下单、查单、未发货订单取消退款和异步回调。 |
| **ZCard 互联** | 内置 ZCard 驱动与 ZCard 供货协议，一套 ZCard 可从另一套 ZCard 拿货；同一实例也可以同时经营零售、接入外部货源并向自己的下游供货。 |

### 已支持的货源协议

| 系统 | 对接方向 | 鉴权 | 商品与履约 |
|---|---|---|---|
| **ACG-Faka / 异次元发卡** | ZCard 作为下游 | MD5 参数签名 | 商品拉取、库存查询、下单拿卡、订单查询；同步交付 |
| **Dujiao Next / 独角数卡 Next** | ZCard 作为下游 | HMAC-SHA256 | 分类/商品分页、库存、下单、查单、取消与签名回调 |
| **ZCard** | 双向 | HMAC-SHA256 + timestamp + nonce | 商品、库存、幂等下单、查单、取消退款、回调；支持 ZCard-to-ZCard 级联 |

> 上游商品同步、定时任务和异步拿货依赖 Laravel Queue；自动调度还需要 Laravel Scheduler。生产配置见下方“常用命令”和[部署安装指南](docs/部署安装指南.md)。

---

## 法律与合规

ZCard 原创代码按 **Apache License 2.0** 开源，许可证允许在遵守其条款的前提下进行商业使用、修改和分发，具体以 [`LICENSE`](LICENSE) 和 [`NOTICE`](NOTICE) 为准。

软件许可不等于经营许可，也不替使用者对商品来源、消费者权益、支付、税务、数据与隐私等事项作合规背书。部署和运营者应自行取得所在国家或地区要求的资质并遵守适用法律；本项目按“原样”提供，不提供任何明示或默示担保。仓库内第三方组件继续适用其各自许可证，例如 `sysadmin/` 所基于的 Art Design Pro 适用其保留的 MIT License。

---

## 环境要求

| 组件 | 版本要求 | 说明 |
|---|---|---|
| **PHP** | **>= 8.3** | 需扩展：pdo_mysql, mbstring, openssl, bcmath, json, curl, gd(或 imagick), redis(可选) |
| **MySQL** | **>= 8.0**（推荐 8.4） | utf8mb4 字符集 |
| **Redis** | **>= 6.0**（可选） | 用于缓存，不装也可运行（降级为 database 缓存） |
| **Composer** | **>= 2.8**（推荐最新） | Laravel 13.8+ 要求 `composer-runtime-api ^2.2`,旧版会报错,详见[部署指南](docs/部署安装指南.md) |
| **Node.js** | **>= 20.19**（可选） | 仅开发/重新编译前端时需要，**生产部署不需要**（编译产物已在仓库）；sysadmin(Vite 7)以 `engines.node` 强制校验，根/storefront(Vite 8)同为此口径 |

> **生产部署无需 Node.js/pnpm** —— `public/admin/` 和 `public/storefront/` 的编译产物已提交到仓库，`git clone` 后可直接访问。

---

## 🤝 赞助商

感谢以下赞助商对本项目的支持（按加入时间排序，持续更新）：

| | 名称 | 简介 |
|---|---|---|
| <img src="docs/ad/898.jpg" width="48" height="48" alt="898 支付" /> | **898 支付** | 聚合几十家支付通道，自研支付系统，一手费率 6%，实时 U 价结算，安全无忧。联系 [@success889_bot](https://t.me/success889_bot) |

---

## Web 服务器配置

ZCard 基于 Laravel 框架，**Web 根目录必须指向 `public/` 子目录**，并配置伪静态规则将所有请求转发到 `public/index.php`。

### Nginx 配置

```nginx
server {
    listen 80;
    server_name your-domain.com;
    root /path/to/ZCard/public;   # ← 必须指向 public/
    index index.php index.html;

    # 超时设置(在线更新可能耗时)
    fastcgi_read_timeout 300;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    # 静态资源不记录日志
    location ~* \.(js|css|png|jpg|jpeg|gif|ico|svg|woff|woff2|ttf|eot)$ {
        expires 30d;
        access_log off;
        try_files $uri =404;
    }

    # PHP-FPM
    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php/php8.3-fpm.sock;  # ← 按实际 socket 路径调整
        fastcgi_index index.php;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
    }

    # 禁止访问敏感文件
    location ~ /\.(?!well-known).* {
        deny all;
    }
    location ~* ^/(storage|bootstrap/cache|vendor|node_modules)/ {
        deny all;
    }
}
```

> **注意**：`root` 必须是 `ZCard/public`，不是 `ZCard/`。如果指向错误，访问首页会看到目录列表或 403。

### Apache 配置

Laravel 自带的 `public/.htaccess` 已处理伪静态，确保 Apache 启用 `mod_rewrite`：

```apache
<VirtualHost *:80>
    ServerName your-domain.com
    DocumentRoot /path/to/ZCard/public   # ← 必须指向 public/

    <Directory /path/to/ZCard/public>
        AllowOverride All                # ← 必须 All，让 .htaccess 生效
        Require all granted
    </Directory>

    # PHP-FPM（如果用 mod_php 则不需要这段）
    <FilesMatch \.php$>
        SetHandler "proxy:unix:/var/run/php/php8.3-fpm.sock|fcgi://localhost"
    </FilesMatch>
</VirtualHost>
```

如果使用共享主机（CPanel/宝塔等），设置网站根目录为 `public` 即可，`.htaccess` 会自动生效。

### 宝塔面板

1. 添加站点 → 域名指向 `ZCard/public`
2. PHP 版本 >= 8.3
3. 伪静态选择 `laravel5`（或粘贴上面的 Nginx 规则）

### 分站域名配置（可选）

如果启用了分站功能，需要支持自定义域名解析：

```nginx
# 通配域名 server_name（让分站域名也能指向同一站点）
server_name your-domain.com *.your-domain.com;

# 或让分站主绑定自己的域名(DNS A 记录指向同一 IP)
# Nginx 不需要额外配置,ResolveSubsite 中间件会自动按 Host 头解析
```

> 📖 **部署遇到问题?** 请先阅读 [《部署安装指南(含常见问题排查)》](docs/部署安装指南.md),覆盖了 Composer 版本、PHP 扩展(fileinfo/bcmath)、宝塔禁用函数(putenv)等高频踩坑点。

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

### 🔄 双向上下游 / 货源对接

#### 作为下游：从其他平台自动拿货

- **3 个真实驱动**：ACG-Faka、Dujiao Next、ZCard，驱动通过自描述配置表单接入，支持同时管理多个货源
- **商品接入**：测试连接与余额、实时预览、按商品勾选导入、上游分类映射；支持全量/增量采集，以及价格、库存、上下架状态的独立同步
- **定时同步**：采集商品、同步价格、同步上下架可分别设置启停、执行间隔和时间窗口；可配置请求间隔，降低触发上游限流的风险
- **本地运营权**：同步上游成本、库存和商品资料；新商品支持比例加价、固定加价、平价或待定价；手动售价默认受保护，也可显式强制重算
- **自动履约**：订单支付后向上游幂等下单；支持同步拿货、异步拿货和同步失败自动转队列重试；卡密与交付说明写入本地订单
- **任务可观测**：同步任务记录进度、处理数量和错误诊断；支持取消、防重复派发、worker 心跳探测与失联任务回收

#### 作为上游：向其他系统开放供货

- **供货账号**：用户可在个人中心申请 API 凭证，支持管理员审核或自动通过；密钥可查看/重置，凭据加密保存
- **预存与账本**：管理员充值/调账，下游下单原子扣减预存余额；每笔充值、扣费、退款都有余额快照和幂等流水
- **灵活供货价**：按下游账号配置商品级或 SKU 级专属价，未配置时回退到商品成本价；未配置有效供货价时拒绝发货
- **完整 API**：连通测试、分类列表、商品列表/详情、库存、创建订单、查询订单、取消未发货订单；自动卡密、固定内容、人工发货和上游转供均可形成履约结果
- **交易安全**：四头 HMAC-SHA256 签名、时间窗口、nonce 防重放、账号/IP 双重限流、回调 URL SSRF 防护、下游订单号幂等、数据库行锁防超卖
- **取消与退款**：未发货供货单可事务内关闭、释放库存并退回下游余额；已发货订单不可取消

对外供货端点：

```text
POST /api/supply/ping
GET  /api/supply/categories
GET  /api/supply/products
GET  /api/supply/products/{id}
GET  /api/supply/products/{id}/stock
POST /api/supply/orders
GET  /api/supply/orders/{id}
POST /api/supply/orders/{id}/cancel
POST /api/supply/callback              # 接收上游异步履约回调
```

### 🛒 商品与卡密

- **商品管理**：分类（树形+图标+排序拖拽）、多 SKU、会员等级定价、最低/最高起购、限购、精选/热标签、虚拟销量与虚拟评价、自定义购买控件
- **卡密系统**：可启用独立密钥的应用层 **AES-256-CBC 加密**存储，明文 sha256 用于去重（去重可按商品开关）；批量导入（≤5000 同步、>5000 入队列），导入批次可撤销
- **库存防超卖**：下单时 `lockForUpdate` 行锁，付款失败/订单超时自动释放
- **4 类履约**：本地自动卡密、固定内容、人工发货、上游自动拿货；订单保存履约类型与交付内容快照

### 💳 订单与支付

- **自动发卡**：付款成功即刻发货（同步事务），支持“标记已用/物理删除”两种模式，邮件+短信通知
- **游客下单**：无需注册，订单查询支持订单号/联系方式 + 查询密码
- **11 个支付驱动**（全部有真实驱动实现）：

  | 通道 | 支持货币 |
  |---|---|
  | 支付宝 / 微信支付 | CNY |
  | PayPal | USD/EUR/GBP |
  | Stripe | USD/EUR/GBP/CNY/JPY |
  | 易支付 / 码支付 | CNY |
  | USDT（多链静态收款） | USDT |
  | EpuSdt | CNY/USD |
  | BEpusdt | CNY/USD/EUR/GBP/JPY，可配置多种加密货币 |
  | OKPay | USDT/TRX |
  | TokenPay | USDT |

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
- 可选 AES-256-CBC 卡密加密存储，密钥与 `APP_KEY` 解耦
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
│   ├── Payment/                   支付契约 + 11 个驱动
│   ├── Supply/                    双向货源：3 个上游驱动 + 对外供货协议/服务
│   ├── Jobs/                      商品同步、上游拿货、批量导入等队列任务
│   ├── Listeners/                 支付后发货、上游拿货、分销与分站结算
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
| `php artisan test` | 运行 PHPUnit 测试 |
| `php artisan tinker` | Tinker REPL |
| `php artisan queue:work` | **生产必配**：队列消费进程（见下方「⚠️ 必须启动队列进程」） |
| `php artisan schedule:work` | 本地持续运行调度器；生产环境应配置每分钟执行 `schedule:run` |
| `php artisan supply:scheduled-sync` | 立即检查各货源计划，并派发已到期的采集/价格/上下架同步任务 |
| `cd storefront && pnpm build` | 重新编译前台（开发时） |
| `cd sysadmin && pnpm build` | 重新编译后台（开发时） |

> ### ⚠️ 必须启动队列进程（生产环境）
>
> 下列功能依赖后台队列（`QUEUE_CONNECTION=database`，任务存在数据库表中）：
>
> - **上游货源拿货**：顾客下单支付后，异步拿货任务 `FetchFromUpstream` 由队列消费（货源为 `async` 模式、或同步拿货失败自动转异步时**必依赖队列**）。**队列不运行 = 订单已支付但永远拿不到上游卡密**。
> - **上游商品同步**：手动全量/增量同步、强制定价重算和定时同步由 `SyncSupplySourceProducts` 消费。
> - **大批量卡密导入**（>5000 条走 `ImportCardsJob`）
> - 其他异步任务
>
> 启动方式（生产用 supervisor 托管，见《部署安装指南》第 4 步）：
>
> ```bash
> php artisan queue:work --tries=3 --timeout=900
> ```
>
> `retry_after` 必须大于 `timeout`（默认 960 > 900）。在线更新会发送
> `queue:restart` 信号并重置 PHP OPcache，生产环境必须由 Supervisor/systemd
> 自动拉起退出后的 worker。若从旧版首次升级后仍出现旧类属性/接口错误，需手动
> 重启一次 PHP-FPM 和 Supervisor。
>
> 验证：`ps aux | grep queue:work` 应有进程；后台「全部同步任务」会同时显示队列健康和 worker 版本。
>
> 自动采集、价格同步、上下架同步还要求服务器每分钟运行 Laravel Scheduler：
>
> ```cron
> * * * * * cd /path/to/ZCard && php artisan schedule:run >> /dev/null 2>&1
> ```

---

## 版本号规则

采用 `x.y.z` 版本号：

- **x**（重大版本）：核心大量重构或破坏性 API 变更
- **y**（主要功能）：公开 API 破坏性变更，可能不兼容前版本
- **z**（修复版本）：BUG 修复/安全修复/新增不破坏兼容的功能

---

## 路线图

### ✅ 已完成
- 双向上下游货源网络：ACG-Faka / Dujiao Next / ZCard 拿货 + ZCard 对外供货 API
- 多货源商品预览/导入/定时同步、自动拿货、同步任务监控、供货账号/专属价/预存账本
- 商品/卡密/SKU/库存防超卖
- 11 个支付驱动
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

## 开源许可

ZCard 原创代码采用 [Apache License 2.0](LICENSE) 发布，版权与归属说明见 [NOTICE](NOTICE)。Apache-2.0 允许商业使用、修改和分发，但分发时须保留许可证、版权与 NOTICE，并对修改作出显著说明。

第三方代码与依赖继续适用其各自许可证；例如 `sysadmin/` 中保留的 Art Design Pro 代码适用 [`sysadmin/LICENSE`](sysadmin/LICENSE) 所列 MIT License。使用本软件不免除部署者遵守当地法律法规和取得必要经营资质的责任。
