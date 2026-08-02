# 多货币 + 多语言 使用指南

ZCard 支持多货币（客户可切换显示货币 + 支付通道按目标货币换算收款）与多语言（storefront 前台 + 后端 API）。

## 一、多货币

### 核心理念
- **基础货币**（默认 CNY）是唯一真相源：所有金额以「基础货币·分」存储，对账/账本都用它。
- **显示货币**纯展示层：客户切换货币浏览，价格按汇率换算显示，**不改存储**。
- **支付换算**独立于显示货币：每个支付通道按各自配置的目标货币收款。

### 管理货币（后台）
进入 sysadmin → 系统设置 → **货币管理**：
- **增删货币**：ISO 4217 三字母代码（CNY/USD/EUR...）、符号、符号位置（前/后）、小数位、汇率。
- **设基础货币**：全局唯一，汇率固定为 1。切换基础货币会自动取消其他货币的基础标记。
- **启用/禁用**：只有启用的货币才在前台货币切换器可见（基础货币始终可见）。
- **改汇率**：基础货币 × 汇率 = 显示金额。汇率过时会导致显示/收款偏差，请定期维护。

### 配置店铺货币（后台 → 系统设置 → 多语言与货币 tab）
- **基础货币**：店铺记账货币。
- **默认显示货币**：客户首次访问的默认货币。
- 汇率以「货币管理」页为准。

### 客户体验（前台 storefront）
- 顶部导航有**货币切换器**，选择后刷新页面、价格按新货币显示。
- 选择会记入 localStorage，下次访问保持。
- 下单时**锁定汇率**：订单快照保存当时的 `display_currency` / `exchange_rate` / `amount_display`，历史订单不随后续汇率变动。

### 支付通道货币（后台 → 支付渠道管理）
每个通道配置两项（在通道 config 字段中）：
- **收款货币（target_currency）**：此通道声明收取的目标货币（审计/记录用）。
- **汇率（exchange_rate）**：基础货币 → 收款货币的换算率（审计/记录用）。

> **重要（实现现状）：** 目前各支付驱动 `pay()` 实际以**基础货币**金额向网关发起收款（未做通道汇率换算）。因此 `payments.charged_amount` 记录的是**基础货币分**（= `orders.amount`），与驱动回调回报的基础货币分口径一致，保证回调金额校验正确。`target_currency` / `channel_exchange_rate` 仅作为该通道声明的目标货币与汇率的**审计元数据**记录，供对账使用。
>
> 若要实现真正的"跨币种收款"（如 PayPal 实际收 USD 而非基础货币），需要进一步改造各驱动 `pay()` 在发往网关前按 `exchange_rate` 换算，并让 `verifyCallback` 回报目标货币单位——这是后续工作。当前阶段，确保所有通道 `exchange_rate=1`（默认）即可正常工作。

各驱动默认收款货币：
| 驱动 | 默认 | 支持货币 |
|---|---|---|
| 支付宝/微信/码支付/易支付 | CNY | CNY |
| PayPal | USD | USD/EUR/GBP |
| Stripe | USD | USD/EUR/GBP/CNY/JPY |
| EpuSdt | CNY | CNY/USD |
| USDT | USDT | USDT |

### 添加新货币
1. 后台货币管理 → 新增（代码/符号/小数位/汇率/启用）。
2. 设置合理汇率（相对基础货币）。
3. 清缓存由系统自动完成（保存即清）。

### 添加新支付通道的货币
在对应驱动的 `getConfigFields()` 里 `target_currency` / `exchange_rate` 字段会自动出现在后台通道编辑页。

---

## 二、多语言

### 范围
- **storefront 前台**：完整 vue-i18n（中文/英文），可切换。
- **后端 API**：错误/提示消息多语言（`lang/zh_CN/messages.php` + `lang/en/messages.php`）。
- **sysadmin 后台**：已有 zh/en（运营内部使用）。

### 客户体验（前台）
- 顶部导航有**语言切换器**（中文/English）。
- 切换即时生效（无需刷新），UI 文案随之切换。
- 选择记入 localStorage，下次访问保持；首次访问按后台配置的**默认语言**（无配置时按浏览器语言猜测）。
- 可选语言由后台「启用的语言」配置决定（禁用的语言不在切换器显示）。

### 后端 API 多语言
通过请求头控制 locale：
- `Accept-Language: en` → 英文消息。
- `X-Lang: en` → 显式指定（前端用此）。
- 默认 zh_CN。

### 配置启用的语言（后台 → 系统设置 → 多语言与货币 tab）
- **启用的语言**：勾选前台可见的语言（zh/en）。
- **默认语言**：首次访问默认。

### 添加新语言（如加日文）
1. **后端**：复制 `lang/en/messages.php` → `lang/ja/messages.php`，翻译。在 `SetLocale` 中间件加 `ja` 识别。
2. **storefront**：`src/locales/langs/` 加 `ja.json`，在 `src/locales/index.ts` 的 messages 注册，在 AppHeader 语言切换器加 `<option value="ja">日本語</option>`。
3. **sysadmin**：`src/locales/langs/` 加 `ja.json`，扩展 `LanguageEnum`。

### 添加新的可翻译字符串
- **前端**：在 `storefront/src/locales/langs/zh.json` + `en.json` 加 key，组件用 `t('namespace.key')`。
- **后端**：在 `lang/zh_CN/messages.php` + `lang/en/messages.php` 加 key，代码用 `__('messages.key')`（带参数用 `__('messages.key', ['name' => $x])`）。

---

## 三、已知限制（v1）

1. **支付通道跨币种收款未端到端实现**：各驱动 `pay()` 实际以基础货币向网关收款，通道 `target_currency`/`exchange_rate` 仅作审计元数据。要实现真正的跨币种收款（如 PayPal 实收 USD）需进一步改造驱动。**当前务必保持通道 `exchange_rate=1`**。详见上文「支付通道货币」。
2. **配置型文案单语言**：后台填写的站点介绍、页脚文案（`footer_about`/`site_description` 等 StorefrontConfig 文本）**不随语言切换**。客户切英文时 UI 框架文案会切换，但这些管理员填写的内容保持单一值。后续如需可在 settings 层扩展多语言值结构。
3. **优惠券折扣消息**：`Checkout` 的优惠券验证消息当前显示基础货币符号（后端 `discount_display` 是基础货币预格式化字符串），未随显示货币换算。
4. **汇率手动维护**：汇率需管理员定期更新；过时汇率会影响显示准确性。
5. **历史订单**：多货币上线前的老订单无汇率快照列（nullable），展示时按基础货币回退。
6. **英文 Laravel 框架翻译不全**：`lang/en/` 仅有 `messages.php`；Laravel 内置的 `auth.*`/`passwords.*`/表单验证在英文 locale 下会回退到框架自带英文或裸 key（应用层 `messages.*` 已完整中英对照）。

---

## 四、技术架构速查

| 层 | 关键文件 |
|---|---|
| 货币字典 | `database/migrations/*_create_currencies_table.php`、`app/Models/Currency.php` |
| 换算服务 | `app/Support/CurrencyService.php`（convert/format/getRate + 缓存） |
| 显示货币中间件 | `app/Http/Middleware/ResolveDisplayCurrency.php` |
| 语言中间件 | `app/Http/Middleware/SetLocale.php` |
| 订单汇率快照 | orders 表 `base_currency`/`display_currency`/`exchange_rate`/`amount_display` |
| 支付换算 | `app/Support/PaymentService.php`（createPayment 通道换算）、payments 表 `charged_*` 列 |
| 支付契约 | `app/Payment/Contracts/PaymentDriver.php`（`getSupportedCurrencies()`） |
| 后端 i18n | `lang/zh_CN/messages.php`、`lang/en/messages.php` |
| 前台 i18n | `storefront/src/locales/`（index.ts + langs/zh.json + en.json） |
| 配置 | `app/Support/StorefrontConfig.php`（base_currency/default_display_currency/enabled_languages/default_language） |

设计 spec：`docs/superpowers/specs/2026-07-31-zcard-multi-currency-language-design.md`
实施计划：`docs/superpowers/plans/2026-07-31-zcard-multi-currency-language.md`
