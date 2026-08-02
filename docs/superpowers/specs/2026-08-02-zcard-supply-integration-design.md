# ZCard 货源对接（Supply Integration）设计文档

- **日期**: 2026-08-02
- **阶段**: 货源对接（Phase 4）
- **状态**: 待评审
- **依赖**: 建立在已完成的商品/订单/支付/多货币/分站之上

---

## 0. 背景与设计依据

### 0.1 目标
ZCard 需要**双向**货源对接能力：
- **作为下游（拿货）**：对接外部上游系统（dujiao-next / acg-faka / 另一个 ZCard），把对方商品同步进本地售卖，顾客下单后调上游 API 拿货，扣上游预存余额。
- **作为上游（供货）**：ZCard 自己开一套供货 API，下游注册账号、拿 token、预存充值、调我们 API 拿货扣预存。

核心诉求之一是「**自己对接自己做货源**」——同一个 ZCard 实例既能当上游又能当下游。

### 0.2 参考系统调研结论（不改对方一行代码）

**dujiao-next（Go/Gin）— 自带完整上游供货 API**
- 端点族：`/api/v1/upstream/*`（ping / categories / products / products/:id / orders / orders/:id / orders/:id/cancel / callback）
- 鉴权：HMAC-SHA256 签名，三头 `Dujiao-Next-Api-Key` + `Dujiao-Next-Timestamp`(±60s) + `Dujiao-Next-Signature`。签名串 = `METHOD\nPATH\ntimestamp\nmd5(body)`
- 账号/余额：下游在对方站注册普通用户 → 申请 API 凭证 → 管理员审批生成 key/secret → 管理员给用户钱包充值（预存）→ 下单自动扣余额
- 幂等：下单传 `downstream_order_no` 防重复
- 发货：同步返回 + 可选签名回调

**acg-faka（PHP 自研框架）— 自带对接接口族**
- 端点族：`/shared/commodity/*`（authentication/connect / items / item / inventory / trade / query/{tradeNo} / valuation / draftCard）
- 鉴权：MD5 签名。`app_id` = 对方站用户 ID，`app_key` = 该用户 app_key。`sign = md5(urldecode(http_build_query(按key排序去空值后的参数)) + "&key=" + app_key)`
- 账号/余额：下游注册普通账号 → 充值 `user.balance` → 用 id 当 app_id、app_key 签名调用
- 发货：**同步**返回，卡密在 `data.secret`
- 协议方言：type 0 原生 `/shared/*`（我们对接这个）、type 1/2 是可选插件

### 0.3 ZCard 现状约束（已核对）
- **框架**：Laravel 13 + PHP 8.3，API-first（`routes/api.php`），Filament v5（`/filament`）+ sysadmin SPA（`/api/admin/*`）+ storefront SPA。
- **金额**：全系统金额字段统一 `bigInteger`，单位**分**。
- **现有成本价**：`products.factory_price`（分）已存在，用于订单成本快照。
- **现有账本**：`bills` 表 + `BillService`（lockForUpdate 原子扣余额 + 流水）。
- **现有分站体系**（`subsite_*` 表 + SubsitePricingService/SettlementService）是「下游分销」方向，本特性的「上游供货」是其**对偶**，可复用其设计模式（idempotency_key、账本状态机、定价查找优先级）。
- **现有驱动模式**：`app/Payment/Contracts/` + `Drivers/` 是多支付通道抽象的成熟范式，本特性的货源驱动直接参照。
- **鉴权**：Sanctum 已配置但未做 abilities 分级；`personal_access_tokens.tokenable` 是 morphs，可挂任意模型。
- **多货币**：`currencies` 表 + `CurrencyService`。本期供货锁定**同币种**。
- **多语言**：`lang/zh_CN/` + `lang/en/`，`__('messages.*')`，`SetLocale` 中间件。

### 0.4 已确认设计决策
1. **双向都做**：作为下游拿货 + 作为上游供货，对称设计。
2. **对外供货用自定义协议**：不复用 dujiao-next 协议，自定义 HMAC 双密钥。
3. **商品映射**：全量同步上游商品进本地 `products` 表（标记来源），可本地自由调价。
4. **发卡时机**：同步试 + 异步回退，发卡模式（sync/async）后台可配。
5. **货源配置支持多个**：可同时接多个上游。
6. **Token 双密钥**：`api_key` + `api_secret`（HMAC）。
7. **专属定价 SKU 级**：`supplier_product_prices` 支持商品级 + SKU 级。
8. **驱动自描述配置 schema**：表单字段由驱动类声明，加新驱动零前端改动。
9. **库存策略后台可选**：实时查询 / 本地缓存同步，每货源独立配置。
10. **初始售价规则后台可选**：固定加价 / 比例加价 / 平价 / 留空待定，默认比例加价 10%。
11. **同步售价保护**：再次同步只更新 factory_price 等「上游拥有」字段，不动本地已设的 `price`。
12. **拿货失败处理后台可选**：人工介入 / 自动退款。
13. **充值纯人工**（第一版），后续扩展在线充值。
14. **多币种**：本期锁定同币种供货，上游币种 ≠ 本站拒绝同步。
15. **配置开关**：两个方向独立开关，默认全关。

---

## 1. 整体架构

### 1.1 核心理念：驱动化对称架构

用一个统一的驱动抽象贯穿两个方向，参照 ZCard 现有 `app/Payment/Drivers/` + `Contracts/` 模式：

```
┌─────────────────────────────────────────────────────────────┐
│                      ZCard 系统                              │
│                                                              │
│  ┌──────────────────── 对外供货 (作为上游) ──────────────────┐ │
│  │  /api/supply/*   ← 自定义 HMAC 协议(双密钥签名)           │ │
│  │  ↓                                                        │ │
│  │  SupplierAccount(供货账号: api_key/api_secret/预存余额)   │ │
│  │  ↓                                                        │ │
│  │  SupplyOrderService → 复用现有 OrderService + Card 发货   │ │
│  │  ↓                                                        │ │
│  │  预存余额扣减(supplier_ledger_entries, 参照 subsite_ledger)│ │
│  └──────────────────────────────────────────────────────────┘ │
│                          ↕  (自己对接自己 = 同一个 ZCard 实例 │
│                             既是供货账号提供方，又是消费方)    │
│  ┌──────────────────── 对接拿货 (作为下游) ──────────────────┐ │
│  │  SupplySource(货源配置: 驱动类型/凭证/参数)               │ │
│  │  ↓                                                        │ │
│  │  SupplyDriver 接口                                        │ │
│  │    ├─ DujiaoNextDriver   (HMAC, 调 /api/v1/upstream/*)    │ │
│  │    ├─ AcgFakaDriver      (MD5,  调 /shared/commodity/*)   │ │
│  │    └─ ZCardDriver        (自定义协议, 调 /api/supply/*)   │ │
│  │  ↓                                                        │ │
│  │  商品同步: 全量拉取→写 products(标记 upstream_source_id)  │ │
│  │  下单拿货: 同步试→失败转队列异步重试+回调接收             │ │
│  │  成本: 写 factory_price, 售价 = 自由定价(可贵可便宜)      │ │
│  └──────────────────────────────────────────────────────────┘ │
└─────────────────────────────────────────────────────────────┘
```

### 1.2 「自己对接自己」如何运作
在 `supply_sources` 建一条 `driver=zcard, base_url=本站地址, credentials={api_key, api_secret}`，指向本站某 `supplier_accounts` 行签发的凭证。`ZCardDriver` 调用的 `/api/supply/*` 就由本实例处理，发货用本地卡库存，**不会无限循环**（供货下单的卡来自本站 cards 表，不再触发上游拿货）。

### 1.3 目录结构
```
app/Supply/
├── Contracts/
│   └── SupplyDriver.php        -- 统一接口(含 configSchema)
├── Drivers/
│   ├── DujiaoNextDriver.php    -- HMAC, 调 /api/v1/upstream/*
│   ├── AcgFakaDriver.php       -- MD5,  调 /shared/commodity/*
│   └── ZCardDriver.php         -- 自定义协议, 调 /api/supply/*
├── Dto/
│   ├── UpstreamProduct.php
│   ├── UpstreamCategory.php
│   ├── UpstreamOrder.php
│   └── UpstreamFulfillment.php
├── SupplyManager.php           -- 工厂: 按 supply_source.driver 返回 Driver
├── SupplySyncService.php       -- 商品同步器
└── SupplyOrderService.php      -- 下游拿货编排(同步试→异步回退)
```

---

## 2. 数据模型

### 2.1 新增表

所有金额字段 `bigInteger`，单位**分**。迁移延续 `2026_08_02` 之后时间戳。

#### 表 1：`supply_sources`（货源配置 —— 后台「货源对接设置」操作的核心）
一行 = 一个上游货源。
| 列 | 类型 | 说明 |
|---|---|---|
| id | bigIncrement | |
| name | string | 运营起的名字，如「主站dujiao」 |
| driver | string | 驱动类型：`dujiao_next` / `acg_faka` / `zcard` |
| base_url | string | 上游地址 |
| credentials | json(加密) | 凭证，结构随 driver 而变（见 §4.3） |
| status | string | `active` / `disabled` |
| settings | json | 驱动相关开关（见 §2.2） |
| last_synced_at | timestamp null | |
| last_error | text null | 最近一次同步/调用错误（排障） |
| balance_cache | bigInteger null | 上游余额缓存（分，ping 时刷新） |
| sort | int | |
| timestamps, softDeletes | | |

#### 表 2：`supplier_accounts`（对外供货账号 —— 别人来对接我们）
| 列 | 类型 | 说明 |
|---|---|---|
| id | bigIncrement | |
| name | string | 账号名/公司名 |
| api_key | string unique | 公开标识（32 位 hex），可明文返回 |
| api_secret | string | 签名密钥（64 位 hex），加密存储，仅生成/reset 时返回明文 |
| balance | bigInteger | 预存余额（分），默认 0 |
| status | string | `active` / `disabled` |
| contact | string null | 联系方式 |
| remark | text null | |
| timestamps, softDeletes | | |

> 不走 `pending` 审批态（运营手动建的，建即生效）。如需审批流后续扩展。
>
> **与现有 `orders` 的关系**：供货下单在本站创建一笔普通 `orders`（`source='supply'`）以复用卡库存发放逻辑，同时写一笔 `supply_orders` 记录下游幂等键/回调。`orders` 表新列见 §2.3。注意供货订单**不经过支付通道**（直接扣预存），故 `orders.payment_channel` 为空、`paid_at` 在事务内直接置位。

#### 表 3：`supplier_product_prices`（给供货账号的专属定价 —— SKU 级）
| 列 | 类型 | 说明 |
|---|---|---|
| id | bigIncrement | |
| supplier_account_id | FK → supplier_accounts | |
| product_id | FK → products | |
| sku_id | FK → product_skus null | null=商品级默认价；非 null=SKU 级专属价 |
| price | bigInteger | 给该账号的拿货价（分） |
| unique(supplier_account_id, product_id, sku_id) | | |
| timestamps | | |

#### 表 4：`supplier_ledger_entries`（供货预存账本）
参照现有 `subsite_ledger_entries`，带幂等键。
| 列 | 类型 | 说明 |
|---|---|---|
| id | bigIncrement | |
| supplier_account_id | FK → supplier_accounts | |
| order_id | FK → orders null | 对应本站 order（下单扣费时有） |
| type | string | `recharge`(充值) / `order`(扣费) / `refund`(退款) / `adjust`(手动调) |
| amount | bigInteger | 正=入账 负=扣费（分） |
| balance_after | bigInteger | 扣后余额快照（分） |
| idempotency_key | string unique | 幂等 |
| remark | string null | |
| timestamps | | |

#### 表 5：`supply_nonce`（防重放兜底表，仅 nonce_store=database 时用）
| 列 | 类型 | 说明 |
|---|---|---|
| id | bigIncrement | |
| nonce | string unique | |
| expires_at | timestamp | 5 分钟后过期，定时清理 |
| timestamps | | |

#### 表 6：`supply_orders`（供货订单记录 —— 对外供货的下单流水）
记录「别人调我们 API 下的单」，与本地 `orders` 表关联（一笔 supply_order 创建一笔本地 order）。
| 列 | 类型 | 说明 |
|---|---|---|
| id | bigIncrement | |
| supplier_account_id | FK → supplier_accounts | |
| order_id | FK → orders | 对应本地 order |
| downstream_order_no | string | 下游的幂等订单号 |
| fulfillment_mode | string | `sync` / `async` |
| callback_url | string null | 下游回调地址 |
| callback_status | string null | `pending` / `sent` / `failed` |
| unique(supplier_account_id, downstream_order_no) | | 幂等 |
| timestamps | | |

> 本地 `orders` 表已有 `subsite_id` 等 source 维度；新增供货订单用独立表 + order 关联，避免污染 orders 表结构。`orders` 表仅加一个可空的 `source` 标记列（见 §2.3）。

### 2.2 `supply_sources.settings` 结构（JSON）
```json
{
  "stock_mode": "realtime",              // realtime | synced
  "auto_sync": true,                     // 自动同步商品
  "sync_frequency": "hourly",            // hourly | daily
  "sync_stock": true,                    // 同步库存(stock_mode=synced 时有效)
  "auto_list": true,                     // 同步后自动上架
  "default_pricing_mode": "percent",     // fixed | percent | equal | pending (新商品初始售价规则)
  "default_markup_amount": 200,          // 分 (mode=fixed 时)
  "default_markup_percent": 10,          // % (mode=percent 时)
  "fulfillment_mode": "sync",            // sync | async (发卡时机)
  "failure_action": "manual",            // manual | auto_refund (拿货失败处理)
  "timeout": 30                          // 秒
}
```

### 2.3 现有表改动
**`products` 表新增列**（migration）：
| 列 | 类型 | 说明 |
|---|---|---|
| upstream_source_id | FK → supply_sources null | 来源货源，null=本地自营 |
| upstream_product_code | string null | 上游商品标识（acg-faka 的 code / dujiao 的 sku_id / zcard 的 slug） |
| upstream_synced_at | timestamp null | |

**`orders` 表新增列**（migration）：
| 列 | 类型 | 说明 |
|---|---|---|
| source | string null | `supply`（该单是供货 API 下单产生的）/ null（正常顾客单） |
| upstream_order_id | string null | 作为下游拿货时，上游返回的订单号 |
| upstream_source_id | FK → supply_sources null | 作为下游拿货时，从哪个货源拿的 |

---

## 3. 驱动接口（三个上游统一抽象）

### 3.1 `SupplyDriver` 接口
```php
interface SupplyDriver {
    /** 驱动自描述：声明它需要的配置字段（表单按 schema 动态渲染） */
    public static function configSchema(): array;

    /** 测连通 + 返回上游余额/名称/币种 */
    public function ping(): array;

    public function listCategories(): array;
    public function listProducts(?Carbon $updatedAfter, int $page): array;
    public function getProduct(string $code): ?UpstreamProduct;
    public function getStock(string $code, ?string $skuCode): int;

    /** 下单拿货（幂等：downstream_order_no） */
    public function createOrder(array $params): UpstreamOrder;
    public function getOrder(string $upstreamOrderId): UpstreamOrder;
    public function cancelOrder(string $upstreamOrderId): bool;

    /** 接收上游异步回调：验签 + 解析 */
    public function verifyCallback(Request $r): ?array;
}
```

### 3.2 驱动自描述 configSchema（核心可扩展机制）
```php
// DujiaoNextDriver
public static function configSchema(): array {
    return [
        'base_url'   => ['type'=>'url',   'label'=>'站点地址', 'required'=>true],
        'api_key'    => ['type'=>'text',  'label'=>'API Key',  'required'=>true],
        'api_secret' => ['type'=>'secret','label'=>'API Secret','required'=>true],
    ];
}
// AcgFakaDriver
public static function configSchema(): array {
    return [
        'base_url' => ['type'=>'url',   'label'=>'站点地址','required'=>true],
        'app_id'   => ['type'=>'number','label'=>'App ID',  'required'=>true, 'help'=>'对方站用户ID'],
        'app_key'  => ['type'=>'secret','label'=>'App Key', 'required'=>true],
    ];
}
// ZCardDriver
public static function configSchema(): array {
    return [
        'base_url'   => ['type'=>'url',   'label'=>'站点地址', 'required'=>true],
        'api_key'    => ['type'=>'text',  'label'=>'API Key',  'required'=>true],
        'api_secret' => ['type'=>'secret','label'=>'API Secret','required'=>true],
    ];
}
```
字段类型收敛为 4 种：`text` / `number` / `url` / `secret`（secret=密码框+脱敏回显+留空不改）。

### 3.3 各驱动对接要点
| 驱动 | 鉴权 | 拿货端点 | 发货 | 金额单位 |
|---|---|---|---|---|
| DujiaoNextDriver | HMAC-SHA256，`sign=hex(HMAC(secret, METHOD\nPATH\nts\nmd5(body)))` | `POST /api/v1/upstream/orders`，幂等 `downstream_order_no` | 同步+回调 | 元→分 `round(f×100)` |
| AcgFakaDriver | MD5，`sign=md5(ksort去空值参数+&key=app_key)`，用 bcmath 处理 float | `POST /shared/commodity/trade`，幂等 `request_no` | 同步(`data.secret`) | 元→分 |
| ZCardDriver | 自定义 HMAC（§4） | `POST /api/supply/orders` | 同步+回调 | 分（直接） |

### 3.4 DTO（屏蔽三家差异）
- `UpstreamCategory`: code, parent_code, name, slug, icon, sort
- `UpstreamProduct`: code, name, description, cover, images[], price(分), factory_price(分), category_code, is_active, skus[], stock_quantity(-1=无限)
- `UpstreamOrder`: id, status, amount(分), currency, fulfillment(UpstreamFulfillment)
- `UpstreamFulfillment`: type, status, cards[]（卡密数组）, delivered_at

金额在 Driver 内部完成「元→分」转换，DTO 及上层一律分。

---

## 4. 对外供货 API（ZCard 作为上游）+ 自定义协议

### 4.1 路由组
独立路由组 `/api/supply/*`，不混入 `/api/`（顾客）和 `/api/admin/*`（后台）。

### 4.2 自定义 HMAC 协议（双密钥）
**四头鉴权**（每个请求必带）：
```
X-Supply-Key        -- api_key（公开标识）
X-Supply-Timestamp  -- Unix 秒，服务器拒绝 ±300s 以外的请求
X-Supply-Nonce      -- 随机串，5 分钟内不可重复
X-Supply-Signature  -- HMAC-SHA256 签名
```
**签名算法**：
```
signString = METHOD + "\n" + PATH(不含query) + "\n" + timestamp + "\n" + nonce + "\n" + md5(rawBody)
signature  = hex_lower( HMAC_SHA256(api_secret, signString) )
```
**中间件流程**：查 `supplier_accounts.api_key` → 校验 status=active → 重放检查（timestamp + nonce，nonce 存 Redis 或 `supply_nonce` 表 5 分钟去重，由 `ZCARD_SUPPLY_NONCE_STORE` 配置）→ `hash_equals` 验签 → 注入 `supplier_account` 到请求上下文。

**防重放双重保护**：timestamp 限窗口 + nonce 单次使用，彻底堵死重放。

### 4.3 端点
| 方法 | 路径 | 用途 |
|---|---|---|
| POST | `/api/supply/ping` | 测连通 + 返回余额/账号名/币种 |
| GET | `/api/supply/categories` | 分类列表 |
| GET | `/api/supply/products` | 商品列表（分页 + `updated_after` 增量，价格按当前账号专属价注入） |
| GET | `/api/supply/products/{id}` | 商品详情（含 SKU + 该账号专属价） |
| GET | `/api/supply/products/{id}/stock` | 实时库存 |
| POST | `/api/supply/orders` | 下单拿货（扣预存，发卡模式按账号/全局配置 sync/async） |
| GET | `/api/supply/orders/{id}` | 查单（含卡密 fulfillment） |
| POST | `/api/supply/orders/{id}/cancel` | 撤单（未发货才退余额） |
| POST | `/api/supply/callback` | 接收上游异步回调（本站作为下游时用，见 §5） |

### 4.4 下单流程 `POST /api/supply/orders`
**请求体**：
```json
{
  "product_id": 123,
  "sku_id": 456,
  "quantity": 1,
  "downstream_order_no": "MY-ORD-1",
  "contact": "buyer@email",
  "callback_url": "https://..."
}
```
**处理流程**（复用现有 OrderService 的卡库存逻辑，不重写发卡）：
```
1. 幂等: (supplier_account_id, downstream_order_no) 已用过?
   → 是: 直接返回已有 supply_order（不重复扣费）
2. 解析商品 + SKU，校验存在 & 上架
3. 算价: 专属价查找(SKU级 → 商品级 → factory_price 兜底) × quantity
4. DB 事务(lockForUpdate on supplier_account):
   a. 余额 >= 应付? 否 → 402 insufficient_balance
   b. lockForUpdate 卡库存 WHERE product_id AND status=unused LIMIT qty (防超卖)
   c. 创建 order(source=supply) + supply_order 记录
   d. 扣余额 + 写 supplier_ledger_entries(type=order, idempotency_key)
   e. 发卡模式:
      sync: 卡标 used + 写 order_deliveries, supply_order.fulfillment_mode=sync
      async: 卡暂不发, 分发本地异步发卡任务, fulfillment_mode=async
   f. 提交事务
5. 若 callback_url 存在且为 async → 异步队列发签名回调
6. 返回 { supply_order_id, order_id, status, amount, fulfillment:{ cards?[] } }
```

### 4.5 定价下发
`GET /api/supply/products` 返回每个商品时，按当前鉴权账号查 `supplier_product_prices` 注入价格。同一商品，账号 A 看到的价 ≠ 账号 B。

### 4.5.1 OrderPaid 事件守卫（重要）
现有三个监听器订阅 `OrderPaid`：`DeliveryService`（发卡）、`CommissionService`（三级分销佣金）、`SubsiteSettlementService`（分站结算）。

供货下单会创建一笔本地 `orders` 行（`source='supply'`）。**必须防止它误触发佣金和分站结算**——供货订单既无分销关系，也无分站归属。

守卫方案（各监听器开头加早退判断）：
```php
// CommissionService::handle / SubsiteSettlementService::handle 开头
if ($event->order->source === 'supply') {
    return; // 供货订单不参与分销/分站结算
}
```
- `DeliveryService::handle`：**不早退**——供货下单的本地发卡也走它（复用发卡逻辑）。但注意：供货下单已在事务内同步发卡（§4.4 步骤 e），故 `DeliveryService` 对 supply 订单应幂等（已发则跳过）。需确认 `DeliveryService` 的幂等性。
- 作为下游拿货（§5.3 `FetchFromUpstream` 监听器）则**不创建本地 orders 触发 OrderPaid**——它是顾客已付款订单的履约环节，订单的 `OrderPaid` 早已在顾客付款时派发过，拿货只是填充卡密，不二次派发事件。

> 实施时需审计 `DeliveryService::handle` 幂等性，并在 `CommissionService`/`SubsiteSettlementService` 加 `source` 守卫。

### 4.6 错误码（统一 JSON）
```json
{ "ok": false, "error_code": "insufficient_balance", "message": "余额不足" }
```
`error_code`（机器可读枚举）：`insufficient_balance`(402) / `insufficient_stock`(409) / `product_unavailable` / `order_not_cancelable` / `bad_request` / `timestamp_expired`(401) / `invalid_signature`(401) / `nonce_reused`(401)。
`message` 按请求 `Accept-Language` 走多语言。

### 4.7 回调签名（我们发出的异步通知）
对下游的异步通知用与 `/api/supply/*` 同一套 HMAC（key/secret/timestamp/nonce/signature），POST 到下游 `callback_url`。payload：`{ event, supply_order_id, order_id, downstream_order_no, status, fulfillment:{...}, timestamp }`。

---

## 5. 商品同步 + 下单拿货编排（作为下游）

### 5.1 商品同步（SupplySyncService）
**Job `SyncSupplySourceProducts`**（队列任务，异步执行）：
```
入参: supply_source_id, mode(full | incremental)
流程:
  driver = SupplyManager::driver($source)
  1. 同步分类: driver->listCategories() → 本地 categories upsert(按 upstream_category_code)
  2. 分页拉商品:
     full:        driver->listProducts(page=1..N)
     incremental: driver->listProducts(updatedAfter=last_synced_at, page=1..N)
  3. 逐商品映射:
     本地有(upstream_product_code 匹配)?
       → 更新: name/description/cover/images/factory_price/SKUs/分类
       → 上游 is_active=false? → products.hide=true(下架不删)
       → price(售价)不动(售价保护)
     本地无?
       → 新建 products 行, 填 upstream_source_id + upstream_product_code
       → 按 settings.default_pricing_mode 算初始 price(price 为空时才套用):
           fixed:   price = factory_price + default_markup_amount
           percent: price = round(factory_price × (1 + percent/100))
           equal:   price = factory_price
           pending: price = null, status=待审(不自动上架)
       → auto_list? status=上架 : status=待审
  4. 记录 last_synced_at + balance_cache(顺带 ping)
  5. 回写 { created, updated, hidden, failed }
异常: 写 supply_sources.last_error
```

**同步字段规则**（售价保护）：
| 字段 | 再次同步 | 原因 |
|---|---|---|
| factory_price（拿货价） | ✅ 更新 | 上游调价 |
| name/description/cover/images/category/SKUs | ✅ 更新 | 商品信息维护 |
| price（你的售价） | ❌ 不动 | 售价所有权归运营 |
| 上游 is_active=false | ➡️ hide=true | 上游不卖了 |

### 5.2 库存策略（后台每货源可选）
**字段 `settings.stock_mode`**（radio: `realtime` / `synced`），tooltip 文案见 §8.2。

| 模式 | 行为 | 优缺 |
|---|---|---|
| 实时查询（默认） | 前台展示时实时调 `driver->getStock()` | 最准、防超卖；依赖上游响应速度 |
| 本地缓存同步 | 定时拷贝库存到本地缓存，前台读缓存 | 快、不依赖上游；有超卖风险，下单时再查兜底 |

### 5.3 下单拿货编排（SupplyOrderService）
触发点：现有 `OrderService::markPaid()` 内 `OrderPaid` 事件。新增监听器 `FetchFromUpstream`：
```
订单 product.upstream_source_id 非空?
  → 是: SupplyOrderService::fulfill()
  → 否: 原有本地发卡逻辑(不动)
```

**`fulfill()` 编排**（同步试 → 异步回退）：
```
driver = SupplyManager::driver($source)
fulfillmentMode = source.settings.fulfillment_mode (sync | async)

sync 模式:
  try driver->createOrder({product_code, sku_id, quantity,
                           downstream_order_no=order.order_no,
                           callback_url=本站 /api/supply/callback})
    成功拿到卡密 → 写 order_deliveries + 卡标 used, delivery_status=delivered
  catch (同步未发卡/超时/库存不足) → 转异步: 分发 FetchFromUpstreamJob, delivery_status=pending

async 模式:
  直接分发 FetchFromUpstreamJob, 不阻塞, delivery_status=pending(等回调)
```

**`FetchFromUpstreamJob`**（队列任务）：
```
退避重试 5 次 (10s/30s/1min/5min/15min)
每次:
  1. order.upstream_order_id 已有? → driver->getOrder() 查状态+卡密
     已发卡? → 写卡密, 标 delivered, 完成
  2. 没上游单? → driver->createOrder() (幂等键防重复)
  3. 仍 pending? → 等下次重试 或 等回调
重试用尽:
  → delivery_status=failed
  → failure_action:
      manual:    后台告警, 等人工
      auto_refund: 全额退款顾客, 订单关闭
```

**回调接收**（`POST /api/supply/callback`，本站作为下游）：
```
driver = SupplyManager::driver(按来源识别)
payload = driver->verifyCallback($r)  // 验签+解析
  → { upstream_order_id, status, cards, downstream_order_no }
匹配本地 order(by downstream_order_no = order.order_no):
  已发卡? 写卡密 + 标 delivered
  已取消? → 退款流程
幂等: 同 upstream_order_id 回调只处理一次
```

### 5.4 退款/撤单对上游的影响
```
本地订单退款 → SupplyOrderService::refund()
  上游订单状态?
    未发货(pending): driver->cancelOrder() → 上游退余额, 本地无卡密损失
    已发货: 上游通常不可退 → 本地承担损失, 运营人工处理
资金流:
  顾客付 price → 我们收入
  我们付 factory_price 给上游(扣上游预存)
  利润 = price - factory_price
  退款: 退顾客 price; 能从上游追回 factory_price 就追回
```

---

## 6. 后台「货源对接设置」

数据层 + API 先做，Filament 和 sysadmin 两端各挂界面。

### 6.1 货源管理 API（`/api/admin/supply-sources/*`，复用 `admin.role`）
| 方法 | 路径 | 用途 |
|---|---|---|
| GET | `/api/admin/supply-sources` | 货源列表（分页） |
| POST | `/api/admin/supply-sources` | 新增货源 |
| GET | `/api/admin/supply-sources/{id}` | 详情（凭证脱敏） |
| PUT | `/api/admin/supply-sources/{id}` | 编辑 |
| DELETE | `/api/admin/supply-sources/{id}` | 删除 |
| POST | `/api/admin/supply-sources/{id}/test` | 测试连通（调 ping，刷新 balance_cache，清 last_error） |
| POST | `/api/admin/supply-sources/{id}/sync` | 触发商品同步（full/incremental） |
| GET | `/api/admin/supply-sources/{id}/sync-status` | 同步进度/结果 |
| GET | `/api/admin/supply-sources/drivers` | 返回各驱动 label + configSchema（供前端动态渲染表单） |

### 6.2 新增货源表单（驱动自描述）
- 选平台 → 拉 `/drivers` 获取该驱动 configSchema → 按 schema 渲染字段（text/number/url/secret）
- 凭证 secret 类字段：填后加密存，回显脱敏（末 4 位），编辑留空=不修改
- 公共配置区：stock_mode / auto_sync / sync_frequency / sync_stock / auto_list / default_pricing_mode(+amount/percent) / fulfillment_mode / failure_action / timeout

### 6.3 凭证安全
- `supply_sources.credentials` 用 `Crypt::encryptString` 加密
- API 返回脱敏（末 4 位）
- 编辑 secret 留空=不修改

### 6.4 测试连通
调对应驱动 `ping()`：
- 成功 → `{ connected:true, upstream_name, balance(分), currency }`，刷新 `balance_cache`，清 `last_error`
- 失败 → `{ connected:false, error }`，写 `last_error`

**余额新鲜度**：定时调度每小时 ping active 货源刷 `balance_cache`；列表页对超过 N 分钟未刷新的懒刷新。显式「测试连通」按钮用于配置后手动验证。

### 6.5 同步触发
- 全量同步：拉全部，本地新建/更新/标记下架
- 增量同步：`updated_after=last_synced_at`，只拉变更（定时任务走这个）
- 异步队列任务 `SyncSupplySourceProducts`，立即返回任务 ID，前端轮询 `/sync-status`

### 6.6 自动同步
Laravel 调度器（`schedule`）按各货源 `settings.sync_frequency` 跑增量同步。

---

## 7. 供货账号管理后台 + 预存充值 + 专属定价

### 7.1 供货账号管理 API（`/api/admin/supplier-accounts/*`）
| 方法 | 路径 | 用途 |
|---|---|---|
| GET | `/api/admin/supplier-accounts` | 账号列表（含余额） |
| POST | `/api/admin/supplier-accounts` | 新建（生成 key/secret，仅此一次返回明文） |
| GET | `/api/admin/supplier-accounts/{id}` | 详情（凭证脱敏） |
| PUT | `/api/admin/supplier-accounts/{id}` | 改名/状态/备注 |
| DELETE | `/api/admin/supplier-accounts/{id}` | 删除 |
| POST | `/api/admin/supplier-accounts/{id}/reset-secret` | 重置 secret（返回新明文一次，旧失效） |
| POST | `/api/admin/supplier-accounts/{id}/recharge` | 充值预存（金额+备注，写 ledger） |
| POST | `/api/admin/supplier-accounts/{id}/adjust` | 手动调整余额（正负，写流水） |
| GET | `/api/admin/supplier-accounts/{id}/ledger` | 账本流水 |

### 7.2 新建账号流程
```
运营填: 账号名、联系方式(可选)、备注(可选)
提交 →
  api_key   = Str::random(32) hex
  api_secret = Str::random(64) hex (加密存)
  balance   = 0, status=active
  → 返回 { id, name, api_key, api_secret(明文,仅此一次), balance:0 }
UI 提示: "请立即复制保存 API Secret，关闭后将无法再次查看"
```
之后查详情只返回 `api_key` + `api_secret` 末 4 位。要看明文只能 `/reset-secret`（生成新 secret，旧的失效，账号/余额/专属价/流水全保留）。

### 7.3 预存充值 + 账本
**充值**（`/recharge`）：
```
入参: amount(分), remark
事务(lockForUpdate on supplier_account):
  balance += amount
  写 supplier_ledger_entries: type=recharge, amount=+, balance_after=快照,
                              idempotency_key="recharge_{id}_{time()}", remark
返回: 新余额
```
**手动调整**（`/adjust`）：正负都行，type=adjust。
**账本流水**（`/ledger`）：分页返回所有 `supplier_ledger_entries`。

充值第一版纯人工（线下收款，运营手动加），后续扩展在线充值。

### 7.4 专属定价管理（SKU 级，两个入口）

**账号维度入口**（`/api/admin/supplier-accounts/{id}/prices`）：
| 方法 | 路径 | 用途 |
|---|---|---|
| GET | `.../prices` | 该账号所有专属价（可按 product 过滤） |
| PUT | `.../prices` | 批量设价（传数组：`[{product_id, sku_id, price}]`） |
| DELETE | `.../prices/{priceId}` | 删除某条（回落到默认） |

界面：进某账号 →「专属定价」标签页 → 搜商品 → 展开 SKU 列表 → 每个 SKU 一个价输入框 → 保存。

**商品维度入口**（`/api/admin/products/{id}/supply-prices`）：
| 方法 | 路径 | 用途 |
|---|---|---|
| GET | `.../supply-prices` | 该商品对各账号的价（含 SKU 级） |
| PUT | `.../supply-prices` | 批量设价（`[{supplier_account_id, sku_id, price}]`） |

界面：进某商品编辑页 →「供货定价」标签页 → 看各账号给什么价。

**定价查找优先级**（供货下单时）：
```
SKU级专属价(supplier_product_prices, sku_id非空)
  → 商品级默认价(supplier_product_prices, sku_id=null)
    → factory_price(兜底)
```

### 7.5 对账视角
供货账号详情页聚合：当前余额（大字）、本月拿货笔数/金额、余额预警阈值（余额 < 阈值后台飘红）。

---

## 8. 横切关注点

### 8.1 多语言（重点）
遵循现有约定：`lang/zh_CN/messages.php` + `lang/en/messages.php`，Filament 走 `lang/{locale}/filament/`。

新增翻译键（归类）：
```php
'supply' => [
    'driver_dujiao_next' => '独角数卡(dujiao-next)',
    'driver_acg_faka'    => 'ACG发卡',
    'driver_zcard'       => 'ZCard',
    'field_base_url' / 'field_api_key' / 'field_api_secret' / 'field_app_id' / 'field_app_key',
    'stock_mode_realtime' / 'stock_mode_realtime_help' / 'stock_mode_synced' / 'stock_mode_synced_help',
    'failure_manual' / 'failure_auto_refund',
    'pricing_fixed_markup' / 'pricing_percent_markup' / 'pricing_equal_cost' / 'pricing_pending',
    'secret_show_once_warning' / 'balance_low_warning',
],
'supply_api' => [
    'insufficient_balance' / 'insufficient_stock' / 'invalid_signature' / 'timestamp_expired' / ...,
],
```
对外供货 API 的错误消息按请求 `Accept-Language` 走多语言；`error_code` 固定英文枚举。

### 8.2 库存模式 tooltip 文案（完整）
**实时查询（推荐）**：
> 顾客在前台看到的库存是**当下向上游发起一次实时查询**的结果。
> - ✅ 最准确，不会超卖（下单前现查）
> - ✅ 无需维护同步任务
> - ⚠️ 前台每次访问多一次对上游请求，依赖上游响应速度；上游慢时商品页略慢
> - ⚠️ 若上游接口无库存查询能力，显示「库存充足」

**本地缓存同步**：
> 定时将上游库存数量**拷贝一份到本地**，前台读本地缓存。
> - ✅ 前台快，不依赖上游实时响应
> - ✅ 适合上游不支持实时查询的场景
> - ⚠️ 有**超卖风险**：同步间隔内上游可能已售罄，本地仍显示有货。下单时会再查一次上游兜底，若已无货则下单失败并退款
> - ⚠️ 缓存可能滞后，展示数字不一定等于真实库存

### 8.3 金额（统一分，全链路）
所有新增表金额字段 `bigInteger` 分。驱动内部做「元→分」转换（用 bcmath/round 避免 float 精度），DTO 及上层一律分。

| 上游 | 原始 | 转换 |
|---|---|---|
| dujiao-next | 元（字符串） | `round(float × 100)` |
| acg-faka | 元（float） | bcmath |
| ZCard | 分 | 直接 |

### 8.4 多币种（本期锁定同币种）
- 对外供货 API：返回价格始终本站基础币种（分），不做展示币种转换。
- 作为下游：Driver 内校验上游币种 = 本站基础币种，不等则**拒绝同步**报错。

### 8.5 安全清单
| 项 | 措施 |
|---|---|
| 凭证存储 | `Crypt::encryptString` 加密 |
| 凭证回显 | secret 脱敏（末 4 位），明文仅生成/reset 时一次 |
| 防重放 | nonce + timestamp(±300s)，nonce 存 Redis 或 `supply_nonce` 表 5 分钟去重 |
| 验签比较 | `hash_equals` 常数时间 |
| 限流 | `/api/supply/*` 每账号 60 次/分钟，超限 429 |
| SSRF | `callback_url` 禁内网地址，仅 http/https |
| 幂等 | 下单 `downstream_order_no`、充值 `idempotency_key`、回调按 `upstream_order_id` |
| 越权 | 中间件注入 `supplier_account`，查询/下单 scope 到该账号 |
| 防超卖 | `lockForUpdate` 卡库存；下游拿货前实时查上游兜底 |
| 日志 | 跨系统调用写日志含 trace_id |

### 8.6 配置开关（`config/zcard.php`）
```php
'supply' => [
    'enabled' => env('ZCARD_SUPPLY', false),
    'upstream_enabled' => env('ZCARD_SUPPLY_UPSTREAM', true),  // 作为下游(拿货)
    'supplier_enabled' => env('ZCARD_SUPPLY_SUPPLIER', true),  // 作为上游(对外供货)
    'nonce_store' => env('ZCARD_SUPPLY_NONCE_STORE', 'redis'), // redis|cache|database
    'rate_limit' => env('ZCARD_SUPPLY_RATE_LIMIT', 60),
    'timestamp_skew' => env('ZCARD_SUPPLY_TS_SKEW', 300),
],
```
默认全关，灰度上线；两方向独立开关。

---

## 9. 范围边界（本期不做）
- 跨币种供货换算（锁定同币种）
- 在线自助充值（第一版人工充值）
- 供货账号审批流（建即生效）
- 第 4 种以上上游驱动（架构已支持扩展，本期实现 dujiao_next / acg_faka / zcard 三种）

---

## 10. 实施顺序建议
1. 数据模型（6 张表 + products/orders 改动）+ 配置开关
2. 驱动抽象（`app/Supply/` 接口 + 3 驱动 + DTO）
3. 对外供货 API（`/api/supply/*` + HMAC 中间件 + 下单发卡）
4. 后台货源对接设置（API + 驱动自描述表单 + 测试连通）
5. 商品同步（SyncService + Job + 售价保护）
6. 下游拿货编排（SupplyOrderService + FetchFromUpstreamJob + 回调）
7. 供货账号管理后台（账号/充值/专属定价两个入口）
8. 多语言文案 + Filament/sysadmin 界面挂载
9. 测试：驱动 ping/同步/下单全链路、并发扣费、幂等、防重放、退款
