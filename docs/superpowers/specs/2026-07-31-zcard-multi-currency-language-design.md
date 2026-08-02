# ZCard 多货币 + 多语言 设计文档

- **日期**: 2026-07-31
- **阶段**: 多货币多语言（横跨商品/订单/支付/前台/后端 API）
- **状态**: 待评审
- **依赖**: 建立在 p1a-p1e 已完成的产品/订单/支付骨架之上

---

## 0. 背景与决策摘要

### 当前现状（已探明）
- **货币**: 全系统所有金额以「分」为单位的整数存储，**无任何货币维度**。隐含假设基础货币为 CNY。前端 storefront 与 sysadmin 各约 10 个文件硬编码 `¥` 符号并复制 `fen/100` 格式化逻辑，无共享工具。
- **支付驱动**: 8 个驱动各自硬编码货币（PayPal=USD、WechatPay=CNY、Stripe/EpuSdt 可配）。`PaymentDriver` 契约（`app/Payment/Contracts/PaymentDriver.php`）**无货币参数**，每个驱动假设 `amount/100 = yuan`。
- **多语言**:
  - **sysadmin**: 已有成熟 vue-i18n（zh/en），易扩展。
  - **storefront**: **零 i18n**，全部硬编码中文，全新搭建。
  - **后端 API**: 无应用级 i18n，所有用户消息为控制器/服务内硬编码中文，无 `__()`、无 `lang/en/`。
- **配置系统**: `StorefrontConfig` + `settings` 表（value 为 JSON cast）是货币/语言配置的天然归宿，读写链路已就绪。

### 参考项目结论
- **dujiao-next（Go）**: 非真正多货币。单一站点货币 + 仅在**支付通道层做换算**（通道 config 携带 `target_currency`+`exchange_rate`，驱动回报 `amount_sent`/`currency_sent`）。无客户可切换的显示货币。
- **acg-faka（PHP）**: 完全无多货币/多语言，无可借鉴。

### 已确认决策
1. **货币范围**: 显示货币（客户可切换）+ 支付换算都要（最完整方案）。
2. **多语言范围**: storefront 前台 + 后端 API 都做（sysadmin 暂仅管配置）。
3. **汇率来源**: 手动设置为主（管理员维护，可选定时任务拉 API 辅助，以手动为准）。
4. **定价策略**: 单一基准价 + 实时换算（商品只存基础货币价，客户选币按汇率实时换算展示，下单时锁定汇率快照）。
5. **货币默认值**: 记住上次选择（localStorage），无 GeoIP。
6. **支付与货币**: 通道配多货币 + 换算（每个通道配支持货币 + 换算汇率，下单时换算，记录实收货币/金额）。
7. **后台范围**: sysadmin 只做配置管理，不做多货币/多语言显示。
8. **取舍**: 配置型文案（footer/about/notice 等 StorefrontConfig 文本）**v1 保持单语言**，完整多语言文案列为已知缺口留待后续。
9. **实施**: 分 3 阶段独立可上线。

---

## 1. 整体架构

### 1.1 核心理念：单一基础货币为唯一真相源 + 两层独立换算

```
            ┌─────────────────────────────────────────────────┐
            │  数据层：所有金额始终以「基础货币·分」存储            │
            │  products.price / orders.amount = 基础货币分       │
            │  （现有 cents 整数存储完全不动）                    │
            └─────────────────────────────────────────────────┘
                    │                              │
        ┌───────────┘                              └────────────┐
        ▼  显示层换算                                    支付层换算 ▼
┌──────────────────────┐                  ┌──────────────────────────┐
│ 基础价 × 显示汇率 →    │                  │ 基础价 × 通道汇率 →        │
│   显示货币（客户浏览/  │                  │   通道目标货币            │
│   下单时看到）          │                  │   （支付时实际向网关收的） │
│ 仅展示，不改存储       │                  │ 支付记录记实收货币+金额    │
└──────────────────────┘                  └──────────────────────────┘
```

### 1.2 关键设计原则
- **基础货币**（默认 CNY）固定不变，所有内部数学运算、对账、记账、商户账本都用它。
- **显示货币**是纯展示层：客户选 USD 浏览，价格按显示汇率换算显示，**绝不改存储**。
- **支付换算与显示货币相互独立**：客户选 USD 但用支付宝时，支付宝按通道配置收 CNY。避免"订单到底以哪个货币结算"的混乱。
- **订单锁定汇率**：下单瞬间快照当时的显示汇率+金额到订单行，历史订单不随后续汇率变动。

---

## 2. 数据模型

### 2.1 新增表：`currencies`（货币字典 + 汇率）

新迁移：`database/migrations/2026_07_31_000010_create_currencies_table.php`

| 字段 | 类型 | 说明 |
|---|---|---|
| `code` | char(3) PK | ISO 4217，如 CNY/USD/EUR |
| `name` | string | 显示名，如"人民币" |
| `symbol` | string | 符号，如 ¥/$/€ |
| `symbol_position` | enum('before','after') | 符号位置（¥ 在前，€ 可配） |
| `decimal_places` | tinyint unsigned | 小数位，CNY/USD=2，JPY=0 |
| `exchange_rate` | decimal(20,8) | 相对基础货币的汇率 |
| `is_base` | boolean | 基础货币（全局唯一，rate 恒为 1） |
| `is_enabled` | boolean | 是否前台可见 |
| `sort` | integer | 排序 |
| timestamps | | |

**汇率定义**: `显示金额 = 基础金额 × exchange_rate`。基准货币行 `is_base=1, rate=1.0`。

种子数据（Seeder）：
- CNY: is_base=1, symbol=¥, before, decimals=2, rate=1.0
- USD: symbol=$, before, decimals=2, rate=0.14（示例，管理员可改）
- EUR: symbol=€, after, decimals=2, rate=0.13（示例）

仅 CNY 默认 `is_enabled=1`，其余默认 0，由管理员按需开启。

### 2.2 现有表改动（最小化）

**`orders` 表新增 4 列（快照，仅记录不参与结算）**
迁移：`2026_07_31_000020_add_currency_snapshot_to_orders_table.php`
- `base_currency` char(3) — 下单时的基础货币 code
- `display_currency` char(3) — 客户选择的显示货币 code
- `exchange_rate` decimal(20,8) — 下单时锁定的显示汇率
- `amount_display` bigint — 显示货币·最小单位（分）

订单的 `amount`（基础货币分）仍是结算真相源，不变。

**`payments` 表新增 3 列**
迁移：`2026_07_31_000030_add_charge_currency_to_payments_table.php`
- `charged_currency` char(3) — 通道实际收款货币 code
- `charged_amount` bigint — 实收金额·最小单位（分）
- `channel_exchange_rate` decimal(20,8) — 通道换算汇率（审计用）

**`payment_channels` 表**: 通道的"支持货币 + 换算汇率"放进现有 `config` JSON（参考 dujiao-next 的 `ExchangeRateConfig`），**不新增列**。config JSON 内新增结构：
```json
{
  "supported_currencies": ["CNY"],
  "target_currency": "CNY",
  "exchange_rate": 1.0
}
```

### 2.3 配置（放进现有 `StorefrontConfig`）

`app/Support/StorefrontConfig.php` 的 `defaults()` 新增 key：
- `base_currency` (string, 默认 `'CNY'`) — 店铺基础货币
- `default_display_currency` (string, 默认 `'CNY'`) — 客户首次访问默认显示货币
- `enabled_languages` (array, 默认 `['zh']`) — 启用的语言 code 列表
- `default_language` (string, 默认 `'zh'`) — 默认语言

---

## 3. 货币解析与换算

### 3.1 新增服务 `app/Support/CurrencyService.php`

```php
class CurrencyService
{
    // 缓存读取的货币表（请求内 + 缓存层）
    public function getCurrency(string $code): array;
    public function getEnabledCurrencies(): Collection;

    // 基础金额(分) → 显示货币金额(分) + 用的汇率
    // 返回 ['amount' => int, 'rate' => float, 'currency' => string]
    public function convert(int $baseFen, string $toCurrency): array;

    // 按货币的符号/位置/小数位格式化为展示字符串，如 "￥12.50" / "12.50€"
    public function format(int $fen, string $currency): string;

    // 读取基础货币 code（来自 StorefrontConfig）
    public function getBaseCurrency(): string;
}
```

**缓存策略**: currencies 表查询结果缓存（`Cache::remember`），管理员改汇率后清除缓存。请求内避免重复查表。

**换算精度**: 用 `bcmath`（`bcmul`）运算，保留 8 位小数中间值，最终按目标货币 `decimal_places` 取整（四舍五入）。

### 3.2 显示货币选择优先级
1. 客户上次选择（storefront 存 localStorage，请求时带 `X-Currency` 请求头 或 query 参数 `?currency=USD`）
2. 兜底：`default_display_currency`（来自 StorefrontConfig）

**无 GeoIP**（决策 5）。

### 3.3 后端中间件 `app/Http/Middleware/ResolveDisplayCurrency.php`
- 注册到 storefront API 路由组（`routes/api.php`）。
- 从请求头/query 解析当前显示货币 → 存入请求属性（`$request->attributes->set('currency', $code)`）。
- 控制器通过 `app('request')->attributes->get('currency')` 或注入 CurrencyService 读取。

### 3.4 API 响应改造（storefront 商品/订单接口）
现有返回 `price`（基础货币分）改为同时返回：
```json
{
  "price_base": 1250,
  "price_display": 175,
  "display_currency": "USD",
  "exchange_rate": 0.14
}
```
前端默认显示 `price_display` + `display_currency`，需要时可回显基准。`OrderService`、`ProductController` 的各返回点改造（参考现有 `getOrderDetail`、`myOrders`、`searchOrders` 等）。

### 3.5 订单汇率锁定（下单流程）
下单 API（`OrderController` 创建订单）：
1. 接收客户选择的货币。
2. 通过 CurrencyService 解析当前汇率。
3. **写入订单快照**：`base_currency`、`display_currency`、`exchange_rate`、`amount_display`。
4. 后续即使汇率变动，该订单展示始终用快照值。

---

## 4. 多语言方案

### 4.1 storefront i18n（全新搭建）
- 引入 `vue-i18n`（Composition API 模式，`legacy: false, globalInjection: true`，对齐 sysadmin 现有做法 `sysadmin/src/locales/index.ts`）。
- 新建目录 `storefront/src/locales/`，含 `index.ts`（createI18n 实例）+ `langs/zh.json` + `langs/en.json`。
- 把现有所有硬编码中文（ProductCard/Checkout/Login/Register/AppFooter 等）抽到 i18n key。
- 语言选择器（Header，与货币切换并列），记住上次选择（localStorage key `zcard.language`）。
- **语言选择优先级**: localStorage > 浏览器 `navigator.language` > `default_language`（StorefrontConfig）。
- storefront 启动时拉取 `/api/settings/storefront`，按 `enabled_languages` 只显示启用的语言。

### 4.2 后端 API 多语言
- **中间件** `app/Http/Middleware/SetLocale.php`：从请求头 `Accept-Language` 读 locale → `App::setLocale()` → 兜底 zh_CN。
- **新建语言文件**:
  - `lang/zh_CN/messages.php` — 应用字符串（错误/提示消息）
  - `lang/en/messages.php` — 英文对照
- **提取硬编码中文**: 把控制器/服务里所有硬编码中文（如 `OrderService` 的"库存不足"、JSON `message` 响应、`JSONException` 类）改为 `__('messages.insufficient_stock')`。FormRequest 验证错误信息也走 lang 文件。
- 全局搜索 `app/` 下硬编码中文字面量，分批迁移。

### 4.3 语言定义枚举（前后端统一）
语言 code 统一小写两字母：`zh`、`en`（与 sysadmin 现有 `LanguageEnum` 一致，`sysadmin/src/enums/appEnum.ts:66`）。

### 4.4 配置型文案取舍（v1 限制）
`StorefrontConfig` 中的文本型配置（`site_name`/`site_description`/`footer_about`/`footer_links`/`site_notice`/`maintenance_message` 等）**v1 保持单语言**。

**理由**: 完整多语言需要把 settings 值改成 `{zh:..., en:...}` 结构，与现有 settings UI 和 JSON value 机制冲突，工作量大。列为已知缺口，后续如需可在 settings 层扩展多语言值。

**影响**: 客户切换 UI 语言时，UI 框架文案（按钮/标签/提示）会切换，但管理员填写的站点介绍/页脚文案保持单一值。需向管理员说明。

---

## 5. 支付通道货币重构（最复杂部分）

### 5.1 契约改造 `app/Payment/Contracts/PaymentDriver.php`
现状: 接口只有 `pay(Order, config)`、`verifyCallback`、`getConfigFields`、`getInfo`，无货币参数。

改造:
- 驱动声明它**支持哪些货币**（新增方法 `getSupportedCurrencies(): array`，如 PayPal 返回 `['USD','EUR']`，Alipay 返回 `['CNY']`）。
- `pay()` 创建支付时传入: 基础货币金额 + 通道目标货币 + 通道汇率（从通道 config JSON 读）。
- 驱动返回: 实际向网关发送的 `amount_sent` + `currency_sent`（回报机制）。

### 5.2 通道配置（config JSON 内）
每个通道实例的 `config` JSON 新增结构：
```json
{
  "supported_currencies": ["CNY"],
  "target_currency": "CNY",
  "exchange_rate": 1.0
}
```
- `supported_currencies`: 此通道支持的货币列表（用于前台筛选可见通道）。
- `target_currency`: 实际向网关收取的货币。
- `exchange_rate`: 基础货币 → 目标货币的换算率。

### 5.3 支付流程
1. 客户下单（金额=基础货币分）。
2. 选支付通道。
3. `PaymentService` 按通道 config 把基础金额换算为目标货币金额（`bcmul`）。
4. 驱动发送给网关，回调时 `verifyCallback` 核对目标货币金额。
5. `payments` 记录 `charged_currency`/`charged_amount`/`channel_exchange_rate`。

### 5.4 各驱动适配（`app/Payment/Drivers/`）
| 驱动 | 改造 |
|---|---|
| `AlipayDriver` | 锁定 CNY（已假设 CNY，显式化 `getSupportedCurrencies()=['CNY']`） |
| `WechatPayDriver` | 去掉硬编码 `'CNY'`（`WechatPayDriver.php:45`），显式声明 CNY-only |
| `CodePayDriver` | 同 Alipay，显式化 CNY |
| `EpayDriver` | 同上，显式化 CNY |
| `PaypalDriver` | 从硬编码 `'USD'`（`PaypalDriver.php:64`）改为通道配置的目标货币 |
| `StripeDriver` | 已有 `currency` 配置（`StripeDriver.php:36`），规范化为新 config 结构 |
| `EpuSdtDriver` | 已有 currency 选项（`EpuSdtDriver.php:60-69,145-148`），规范化 |
| `UsdtDriver` | 加密币特殊处理（目标货币=USDT，汇率=USDT 单价，`UsdtDriver.php:22-33`） |

### 5.5 前台通道可见性
storefront 结账页根据客户所选显示货币 / 基础货币筛选通道：仅显示 `supported_currencies` 含相关货币的通道。（具体筛选逻辑见实现计划。）

---

## 6. 前端 storefront 改造

### 6.1 统一格式化工具
新建 `storefront/src/utils/money.ts`：
```ts
// 按货币的符号/位置/小数位格式化
export function formatMoney(fen: number, currency: CurrencyInfo): string
// 基础货币分 → 显示货币分（用前端缓存的汇率，仅用于即时预览；下单以服务端为准）
export function convertFen(baseFen: number, rate: number): number
```
替换现有 ~10 个文件里的 `¥` + `(fen/100).toFixed(2)` 重复代码：
- `storefront/src/components/ProductCard.vue:12,26-27,45`
- `storefront/src/views/Home.vue:23,82`
- `storefront/src/views/Product.vue:41,77-78,102`
- `storefront/src/views/Checkout.vue:70,165,173,247`
- `storefront/src/views/MyOrders.vue:24,78`
- `storefront/src/views/OrderQuery.vue:41,151-152`
- `storefront/src/views/PayResult.vue:79`
- `storefront/src/api/products.ts:3,5`、`orders.ts:4,8`（TS 接口加 currency 字段）

### 6.2 Header 切换器
`storefront/src/components/AppHeader.vue` 新增：
- 货币切换下拉（拉取启用货币列表，选中后存 localStorage + 带请求头 + 刷新当前页价格）。
- 语言切换下拉（与货币并列）。

### 6.3 货币/语言状态
建议放 storefront 现有 settings store（pinia）或新建 preferences store，存当前货币 + 当前语言，响应式驱动全站重渲染。

---

## 7. sysadmin 后台（只做配置管理）

- **不做**多货币/多语言显示（运营内部看基础货币即可）。
- **新增货币管理页**: CRUD `currencies` 表（增删货币、改汇率、启用/禁用、设基础货币、排序）。位置: 新菜单或 settings 内 tab。
- **语言管理**: 在现有 settings 页（`sysadmin/src/views/setting/index.vue`）加 tab，配 `enabled_languages`/`default_language`、`base_currency`/`default_display_currency`。
- sysadmin 已有 zh/en，本次可选扩更多语言（复用 `LanguageEnum` 机制）。
- 后台金额显示保持现状（基础货币分 /100），不做货币切换。

---

## 8. 实施分阶段

每阶段独立可上线、独立可回滚。

### 阶段一 · 货币基础设施（不含支付换算）
- `currencies` 表 + Seeder
- `CurrencyService`（convert/format/getRate + 缓存）
- `StorefrontConfig` 新增货币配置 key
- `ResolveDisplayCurrency` 中间件
- storefront 商品/订单 API 响应增加 display 字段
- 下单汇率锁定（orders 快照 4 列）
- 前端统一 `formatMoney` + 货币切换器
- sysadmin 货币管理页
- **交付价值**: 客户能切货币浏览、下单锁定汇率。

### 阶段二 · 支付换算重构
- `PaymentDriver` 契约改造（货币参数 + `getSupportedCurrencies`）
- 通道 config JSON 货币结构 + 后台编辑 UI
- 8 个驱动适配（逐一）
- `payments` 快照 3 列 + PaymentService 换算逻辑
- 前台通道可见性筛选
- **交付价值**: 跨国收款，每个通道按目标货币实际收取。

### 阶段三 · 多语言
- storefront vue-i18n 搭建 + 全部硬编码中文抽取（zh/en）
- 后端 `SetLocale` 中间件 + `lang/zh_CN|en/messages.php`
- 控制器/服务硬编码中文 → `__()` 提取
- StorefrontConfig 语言配置 + sysadmin 语言管理 tab
- 前端语言切换器
- **交付价值**: 前台 + API 完整中英双语。

---

## 9. 已知缺口与风险

1. **配置型文案单语言（v1）**: footer/about/notice 等 StorefrontConfig 文本不随语言切换。已在 §4.4 说明，留待后续。
2. **历史数据**: 阶段一上线前的订单无快照列（nullable），展示时按基础货币回退显示。
3. **汇率手动维护风险**: 汇率过时会导致显示/收款偏差。管理员需定期维护；可选定时任务拉 API 辅助（不在本 spec 范围）。
4. **支付驱动重构影响面大**: 8 个驱动逐一适配，阶段二需充分回归测试每个支付通道的回调金额核对。
5. **USDT 等加密币**: 汇率机制与法币不同（USDT 单价），实现时需特殊处理。

---

## 10. 未决问题（实现计划阶段细化）

- 前台通道可见性筛选：按客户显示货币还是按基础货币筛选通道？（倾向：按通道 target_currency 与客户可支付能力综合，实现时定）
- 货币切换是否需要页面整体刷新还是局部响应式更新？（倾向：响应式，价格区域 watch 货币）
- 后端 `__()` 提取的覆盖范围边界（邮件模板、Filament 面板是否纳入？）

---

## 附: 关键文件索引

**新建**
- `database/migrations/2026_07_31_000010_create_currencies_table.php`
- `database/migrations/2026_07_31_000020_add_currency_snapshot_to_orders_table.php`
- `database/migrations/2026_07_31_000030_add_charge_currency_to_payments_table.php`
- `app/Support/CurrencyService.php`
- `app/Http/Middleware/ResolveDisplayCurrency.php`
- `app/Http/Middleware/SetLocale.php`
- `lang/zh_CN/messages.php`、`lang/en/messages.php`
- `storefront/src/locales/index.ts` + `langs/zh.json` + `langs/en.json`
- `storefront/src/utils/money.ts`

**改动**
- `app/Support/StorefrontConfig.php`（defaults 新增 4 key）
- `app/Payment/Contracts/PaymentDriver.php`（契约）
- `app/Payment/Drivers/*.php`（8 驱动）
- `app/Support/PaymentService.php`、`OrderService.php`
- `app/Http/Controllers/Api/OrderController.php`、`ProductController.php`
- `routes/api.php`（中间件注册）
- `storefront/src/components/AppHeader.vue`、`ProductCard.vue` 等 ~10 文件
- `sysadmin/src/views/setting/index.vue` + 新货币管理页
