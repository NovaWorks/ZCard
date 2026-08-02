# ZCard 分站（Sub-site / 白标店铺）设计文档

- **日期**: 2026-08-01
- **阶段**: 分站（Phase 3 子项）
- **状态**: 待评审（逐节核对中）
- **依赖**: 建立在已完成的商品/订单/支付/多货币/三级分销之上

---

## 0. 背景与设计依据

### 参考系统调研结论
- **dujiao-next（Go）reseller 模块**：分站=纯转售主站商品的加价分销渠道。域名验证+Redis 缓存、订单快照、冻结期账本、防自购、FIFO 提现、幂等 key。**架构最成熟**。
- **acg-faka（PHP）分站**：分站=商户(可自营+转售)。**12 个架构弱点**：域名检测脆弱(每 handler 重复查/不归一化/无缓存)、无 DNS 验证、无订单快照、立即结算无冻结、防自购仅直接关系、无幂等、命名冲突(order.owner=买家 vs user_id=商户)、加价两套公式不一致、whereRaw SQL 注入面、user_commodity 无唯一约束、批量操作无事务。

### ZCard 现状约束（已核对）
- **单商户运营**：所有商品/分类 merchant_id=1，storefront 查询完全忽略 merchant_id。
- **商品归属主站**：分站不拥有商品 → **不能用 merchant_id 做分站隔离**。
- **卡密发货**：DeliveryService 按 product_id/order_id 取卡密，与 merchant 无关 → 分站订单发货零改造复用。
- **支付**：PaymentService 不关心 merchant → 复用主站支付通道。
- **多货币**：CurrencyService::convert 在显示层；分站加价在基础货币层(先于 convert) → 两层独立。
- **三级分销**：CommissionService 监听 OrderPaid 按毛利发佣。分站订单利润归分站主，**与分销互斥**(需守卫)。
- **部署**：单 Sail 容器、单入口、SPA 同源、无通配 DNS/cors.php → Host 头解析是最低摩擦方案。
- **配置**：StorefrontConfig 是全局单租户。分站级配置放 merchants.settings JSON。

### 已确认设计决策
1. **纯转售模型**（dujiao-next 路线）：分站只转售主站共享目录(加价)，不上架自营。
2. **不用 merchant_id 隔离**：用独立 subsite_id 列 + subsite_product_settings 表。
3. **分站=一个 Merchant 行**：复用 merchants 表，配置存 settings JSON。
4. **域名 Host 头解析**：同时支持二级域名 + 自定义域名。
5. **与分销互斥**：分站订单不发分销佣金。
6. **取 dujiao-next 全部优点 + 规避 acg-faka 全部弱点**（见 §9）。

---

## 1. 整体架构

### 1.1 核心理念：分站 = 主站的加价分销渠道

```
    主站(merchant_id=1)拥有商品+卡密+支付通道+发货
                │
    ┌───────────┼───────────┐
    ▼           ▼           ▼
 分站A       分站B       分站C   (各=一个 Merchant 行, user_id=分站主)
 alice.com  bob.zcard.com  shop.c.com
    │           │           │
    └─── 转售主站商品(加价), 配置: 哪些商品可见 + 每个加多少价
                │
    客户在分站下单 → 按分站加价定价 → 快照 → 付款 → 主站发卡
                │
    利润(分站价−基础价) → 冻结期账本 → 分站主余额 → 可提现
```

### 1.2 请求流转（域名解析）
```
请求 Host: alice.com
    ↓
ResolveSubsite 中间件(prepend 到 api 组,最早执行)
    ├─ 归一化 host(lowercase/剥离端口/剥离www/punycode)
    ├─ Redis 查 subsite:domain:{host}(正缓存5min/负缓存60s)
    ├─ DB 查 subsite_domains(status=active AND verification=verified)
    └─ 存入 request()->attributes->set('subsite', $merchant) 或 null(主站)
    ↓
控制器读 request()->attributes->get('subsite') → 决定商品可见性 + 加价
```

### 1.3 关键设计原则
- **分站不碰库存/发货/支付**：卡密、支付通道、发货全在主站，分站订单零改造复用。
- **分站只负责**：商品可见性过滤 + 加价定价 + 利润归属。
- **merchant_id 不变**：始终=主站(1)。分站隔离靠独立 subsite_id 列。
- **与分销互斥**：分站订单利润归分站主，不发分销佣金。

---

## 2. 数据模型

### 2.1 复用现有
- **merchants 表**：分站=一个 Merchant 行。slug/settings JSON/commission_rate/user_id。主站 id=1 不变。
- **merchant_id**（products/orders 等）：保持原义不动（商品归属=主站）。
- **users.balance / bills / withdrawals**：分站主利润进 balance，复用现有提现流程。

### 2.2 merchants.settings JSON 结构（分站级配置）
```json
{
  "is_subsite": true,
  "default_markup_percent": 10,
  "max_markup_percent": 50,
  "settlement_confirm_days": 7,
  "site_name": "Alice 的店铺",
  "logo": "",
  "favicon": "",
  "announcement": "",
  "support_contact": ""
}
```
主站 merchant(id=1)的 settings 无 is_subsite 或 is_subsite=false。

### 2.3 新增表：subsite_domains（域名绑定）
迁移：2026_08_01_000020_create_subsite_domains_table.php

| 字段 | 类型 | 说明 |
|---|---|---|
| id | bigIncrements | |
| merchant_id | FK→merchants | cascadeOnDelete |
| domain | string(255) | 归一化域名(lowercase,唯一索引) |
| type | enum('subdomain','custom') | 系统分配/自定义 |
| verification_token | string(128) nullable | 自定义域名验证用 |
| verification_status | enum('pending','verified','failed') default 'pending' | |
| status | enum('pending_review','active','disabled') default 'pending_review' | |
| is_primary | boolean default false | |
| verified_at | timestamp nullable | |
| timestamps | | |

唯一索引 domain。查表要求 status='active' AND verification_status='verified'。

### 2.4 新增表：subsite_product_settings（每分站每商品加价规则）
迁移：2026_08_01_000030_create_subsite_product_settings_table.php

| 字段 | 类型 | 说明 |
|---|---|---|
| id | bigIncrements | |
| merchant_id | FK→merchants | cascadeOnDelete |
| product_id | FK→products | cascadeOnDelete |
| sku_id | unsignedBigInteger default 0 | 0=商品级;>0=SKU级 |
| is_listed | boolean default true | 此分站是否上架 |
| pricing_mode | enum('inherit','markup_percent','fixed_markup','fixed_price') default 'inherit' | 4 模式 |
| markup_percent | decimal(8,2) default 0 | 百分比加价 |
| fixed_markup_amount | bigInteger default 0 | 固定加价(分) |
| fixed_price_amount | bigInteger default 0 | 一口价(分) |
| sort_order | integer default 0 | |
| timestamps | | |

唯一索引 (merchant_id, product_id, sku_id)。删除规则时硬删除(非软删除)避免唯一约束冲突。

### 2.5 新增表：subsite_ledger_entries（分站利润账本）
迁移：2026_08_01_000040_create_subsite_ledger_entries_table.php

| 字段 | 类型 | 说明 |
|---|---|---|
| id | bigIncrements | |
| merchant_id | FK→merchants | cascadeOnDelete |
| order_id | FK→orders nullable | nullOnDelete |
| type | string(32) | order_profit/refund_deduct/withdraw_lock/withdraw_paid/manual_adjust |
| amount | bigInteger | 有符号(分):正=收入,负=扣除 |
| status | string(32) default 'pending' | pending/available/locked/withdrawn/canceled |
| available_at | timestamp nullable | 冻结到期时间 |
| withdraw_request_id | FK→withdrawals nullable | 提现锁定时关联 |
| idempotency_key | string(160) | **唯一索引**(幂等防重复) |
| remark | text nullable | |
| timestamps | | |

索引：(merchant_id, status)、(available_at)。

### 2.6 新增表：subsite_order_snapshots（订单定价快照）
迁移：2026_08_01_000050_create_subsite_order_snapshots_table.php

| 字段 | 类型 | 说明 |
|---|---|---|
| id | bigIncrements | |
| order_id | FK→orders **unique** | 一订单一快照 |
| merchant_id | FK→merchants | |
| domain | string(255) | 下单时分站域名快照 |
| reseller_user_id | FK→users | 分站主(防自购比对) |
| buyer_id | FK→users nullable | 0/null=游客 |
| base_amount | bigInteger | 基础金额(分) |
| reseller_amount | bigInteger | 分站售价(分,买家实付) |
| profit_amount | bigInteger | 利润(=reseller−base,分) |
| profit_eligible | boolean default true | false=防自购拦截 |
| profit_block_reason | string(64) nullable | self_dealing_owner/self_dealing_upline |
| pricing_snapshot | json | 行级定价明细 |
| risk_snapshot | json | 防自购审计 |
| timestamps | | |

### 2.7 orders 表新增列
迁移：2026_08_01_000060_add_subsite_fields_to_orders_table.php
- subsite_id FK→merchants nullable（NULL=主站订单）
- subsite_domain string(255) nullable
- subsite_profit bigInteger default 0（分站利润快照,分）

### 2.8 全局配置
StorefrontConfig::defaults() 新增：subsite_enabled(false)、subsite_default_confirm_days(7)、subsite_subdomain_base('')。
config/zcard.php 的 features.sub_site(已存在,默认 false)作总开关。


---

## 3. 域名解析与租户上下文

### 3.1 中间件 app/Http/Middleware/ResolveSubsite.php
- 读 $request->host() → normalizeHost(lowercase/剥离端口/剥离www/剥离尾点/punycode idn_to_ascii)
- config('zcard.features.sub_site') 未开 → 直接放行
- Redis 缓存：正缓存 5min(subsite:domain:{host})、负缓存 60s(未命中也缓存 false 避免反复查表)
- DB 查 subsite_domains(domain=host AND status=active AND verification_status=verified) → 找到则取其 merchant
- 存入 request()->attributes->set('subsite', $merchant)（null=主站）

### 3.2 注册（bootstrap/app.php）
在 $middleware->api(prepend: [...]) 数组加入 ResolveSubsite::class（最早执行，与 MaintenanceMiddleware 同级）。

### 3.3 缓存失效
分站域名/状态变更时（后台审批/绑定/解绑），Cache::forget("subsite:domain:{host}")。

---

## 4. 商品可见性与定价

### 4.1 商品查询改造（ProductController index/show/featured）
- 取 $subsite = request()->attributes->get('subsite')。
- 若 $subsite：加载该分站的 subsite_product_settings，过滤 is_listed=false 的商品，对可见商品应用加价（调 SubsitePricingService）。
- 若主站(null)：保持现有逻辑（所有商品原价）。

### 4.2 定价引擎 app/Support/SubsitePricingService.php（单一函数，listing 与 checkout 共用）
解析优先级：SKU规则 > 商品规则 > 分站默认加价率 > 继承原价（第一个非 inherit 生效）。
- applyMode 4 模式：markup_percent = base×(100+pct)/100；fixed_markup = base+金额；fixed_price = 绝对值；inherit = 原价。
- 校验：分站价 ≥ 基础价。listing 失败隐藏单个 SKU+告警；checkout 失败中止下单。

### 4.3 4 种加价模式
| 模式 | 公式 |
|---|---|
| inherit | 原价透传 |
| markup_percent | base × (100 + pct) / 100 |
| fixed_markup | base + 固定金额 |
| fixed_price | 绝对值 |

---

## 5. 下单流程改造

### 5.1 OrderService::createOrder 改造（在 DB::transaction 内）
1. 取 $subsite = request()->attributes->get('subsite')。
2. 若 $subsite：商品可见性校验 → SubsitePricingService::resolveUnitPrice → $unitPrice=分站价 → $amount=$unitPrice×qty → 清零优惠券/会员折扣（分站订单不享受主站折扣）→ 防自购校验(§6)。
3. Order::create([...]) 新增：subsite_id、subsite_domain、subsite_profit(=reseller_amount−base_amount)。
4. 若分站订单：插入 subsite_order_snapshots（完整定价快照）。
5. 卡密锁定、发货等完全复用现有逻辑（按 product_id 取卡，与 merchant 无关）。

### 5.2 金额单位（始终整数分）
基础价 base、分站价 price、利润 profit 均整数分。多货币 convert 在快照后（两层独立）。

---

## 6. 防自购（Anti-Self-Dealing）

在 createOrder 内、写快照前：
1. **owner 直接匹配**：buyer_id == $subsite->user_id → profit_eligible=false，reason=self_dealing_owner
2. **上级链匹配**（复用分销 pid 链）：遍历 buyer 的 parent 链（最多3级），若含 $subsite->user_id → profit_eligible=false，reason=self_dealing_upline
3. **订单照走**：防自购只拦利润不拦订单。买家照常付款收货；利润归平台(profit_amount=0 写快照)。

risk_snapshot JSON 记录审计字段。

---

## 7. 利润结算（SubsiteSettlementService）

### 7.1 OrderPaid 监听器（注册到 AppServiceProvider::boot，与 DeliveryService/CommissionService 并列）
- 门控：config('zcard.features.sub_site') 开启 + $order->subsite_id 非空
- 取快照，若 !profit_eligible || profit<=0 → return
- 幂等：idempotency_key = "order_profit:{order_id}" 唯一
- 写 ledger：type=order_profit，amount=profit，status=pending(冻结)或available(confirm_days=0)，available_at=now+confirm_days

### 7.2 冻结期到期（定时任务）
routes/console.php 注册每日任务：status=pending 且 available_at<=now 的转 available。

### 7.3 提现（方案 A：FIFO 账本提现，已确定）
分站主申请提现 → FIFO 消费 available ledger 条目（按 available_at 升序）→ 写 withdrawals + bills → 后台审批。**实时 SUM(available ledger) 校验，不信任缓存余额**（dujiao-next 教训）。部分提现时拆分 ledger 行保留审计。驳回时退回 available。

### 7.4 与分销互斥
CommissionService::handle 开头加：if ($order->subsite_id) return;（分站订单不发分销佣金）。

---

## 8. 分站管理（后台 + 分站主自助）

### 8.1 后台管理（sysadmin）
分站列表(merchant where settings->is_subsite=true)、审批/禁用、域名审批、分站订单筛选、分站结算(ledger/提现审批)。

### 8.2 分站主自助（控制台只在主站，已确定 dujiao-next 模式）
分站主在**主站**登录后管理（不在自己分站域名下），两层守卫：
- **服务端**：分站管理 API 路由组加中间件，检测到当前请求来自分站域名(subsite 非空)→ 403 拒绝（参考 dujiao-next RequireMainTenantForResellerConsole）
- **前端**：storefront 路由 /my-subsite 加 meta.requiresMainSite，检测当前 tenant 为分站时隐藏入口/重定向

分站主功能：开通分站、域名管理(二级域名/自定义域名+验证)、商品配置(选商品+加价)、财务(利润账本/FIFO提现)、店铺设置(站名/logo/公告，存 merchant.settings)。

分站域名访问时：购物面正常(加价商品)，管理入口完全隐藏。

### 8.3 白标展示
分站域名访问时，前台 storefront 读 request()->attributes->get('subsite') 的 settings，渲染该分站的站名/logo/公告（覆盖主站 StorefrontConfig 对应字段）。

---

## 9. 与两个参考系统的对比（ZCard 更优之处）

| 维度 | acg-faka（弱） | dujiao-next（强） | **ZCard（更优）** |
|---|---|---|---|
| 域名检测 | 每 handler 重复查/不归一化/无缓存 | Redis 缓存+验证 | 早期中间件一次解析 + Redis 正负缓存 + 完整归一化 |
| 域名绑定 | 仅正则+唯一,无验证 | 验证 token | DNS/HTTP token 验证 + 主域名黑名单 + 归一化存储 |
| 订单快照 | 无(只存最终 rebate) | 有 | 有:行级定价明细 + 风控审计 |
| 结算 | 立即入 coin,无冻结 | 冻结期账本 | 冻结期账本(可配天数) + FIFO 提现 + 实时 SUM 校验 |
| 防自购 | 仅直接关系 | owner+关联账号 | owner + buyer 全上级链(复用 pid) + 只拦利润 |
| 幂等 | 仅状态标志 | ledger 幂等 key | idempotency_key 唯一约束 + order_id 唯一快照 |
| 命名 | order.owner=买家 vs user_id=商户(冲突) | — | 清晰:subsite_id/buyer_id,不重载 merchant_id |
| 加价公式 | 两套不一致(百分比 vs 绝对值 bug) | 4 模式统一 | 单一 SubsitePricingService,listing 与 checkout 共用 |
| 分账 SQL | whereRaw 拼接(注入面) | — | 全程 whereIn 绑定参数 + ORM |
| 配置存储 | 分散表 | — | merchants.settings JSON(分站级) + StorefrontConfig(全局)分层 |
| 唯一约束 | user_commodity 无唯一(数据陷阱) | partial unique | 普通唯一索引 + 硬删除规则 |
| 批量操作 | N 次保存无事务 | — | DB::transaction 包裹 |
| 商品来源 | 自营+转售(三场景复杂) | 纯转售 | 纯转售(复用主站发卡/支付) |

---

## 10. 实施分阶段

### 阶段一 · 分站基础（域名解析 + 商品可见性 + 加价定价）
4 张表迁移+模型、ResolveSubsite 中间件+域名归一化+Redis 缓存、SubsitePricingService(4模式)、ProductController 改造、后台分站/域名管理。

### 阶段二 · 分站下单 + 利润结算
OrderService 改造(定价+快照+防自购)、orders 加 subsite_* 列、SubsiteSettlementService(OrderPaid 监听+冻结账本)、与分销互斥守卫、冻结到期定时任务。

### 阶段三 · 分站主自助 + 白标
分站主自助控制台(开通/域名/商品配置/财务)、白标展示、提现。

---

## 11. 已知取舍 / v1 范围
1. 纯转售：分站不上架自营商品。
2. 不做分站独立支付通道。
3. 不做分站级 StorefrontConfig（主题/布局全局；分站只配站名/logo/加价）。
4. 冻结期默认 7 天（可配，0=立即）。
5. 退款追回：v1 预留 refund_deduct 类型，暂不实现。

---

## 12. 决策记录（已全部确定）

| # | 问题 | 决定 | 依据 |
|---|---|---|---|
| 1 | 提现方案 | **方案 A：FIFO 账本提现** | 用户确认；审计粒度好，实时 SUM 校验 |
| 2 | 系统二级域名 | **二级域名 + 自定义域名都做** | 用户确认；代码层统一 Host 解析，运维配通配 DNS/证书 |
| 3 | 分站主控制台位置 | **控制台只在主站（dujiao-next 模式）** | 调研：dujiao-next 硬隔离两层守卫(服务端中间件403+前端tenant判断)，比 acg-faka 无隔离更安全。分站域名访问时隐藏管理入口。 |
| 4 | 平台抽成 | **不额外抽成（dujiao-next 模式）** | 调研：dujiao-next 分站主拿100%加价、平台靠base价赚钱；比 acg-faka 双重抽成(cost%+base)更透明。分站主实得=分站售价−基础价。commission_rate 保留不用。 |

---

## 附: 关键文件索引

**新建**
- app/Http/Middleware/ResolveSubsite.php
- app/Support/SubsitePricingService.php
- app/Support/SubsiteSettlementService.php
- app/Models/SubsiteDomain.php、SubsiteProductSetting.php、SubsiteLedgerEntry.php、SubsiteOrderSnapshot.php
- app/Http/Controllers/Api/Admin/SubsiteController.php（分站/域名管理）
- 4 张迁移 + orders 加列迁移

**改造**
- app/Http/Controllers/Api/ProductController.php（可见性 + 加价）
- app/Support/OrderService.php（分站定价 + 快照 + 防自购）
- app/Support/CommissionService.php（互斥守卫）
- app/Providers/AppServiceProvider.php（注册 settlement 监听器）
- bootstrap/app.php（注册 ResolveSubsite 中间件）
- app/Support/StorefrontConfig.php（3 个分站配置 key）
- routes/console.php（冻结到期定时任务）
- storefront 前端（白标 + 分站主控制台，阶段三）
