# ZCard P1-D — 支付网关 设计（Spec）

> Phase 1 第四个子项目。支付通道抽象 + 6 个内置 Driver(支付宝/微信/USDT/码支付/PayPal/Stripe)。
> 本文档不进 git（`.gitignore` 忽略整个 `docs/`）。

- **日期**:2026-07-29
- **范围**:P1-D(支付网关:Driver 抽象 + 6 内置通道 + 后台管理 + 前台收银台选通道)
- **状态**:待实现
- **参考**:`acg-faka/app/Pay/`(Pay 接口 + Base + Signature + 插件式加载)

---

## 1. 定位与范围

### 1.1 P1-D 是什么

P1-D = **支付网关**。P1-C 完成了订单交易闭环(下单→模拟支付→发货),P1-D 把"模拟支付"替换为**真实支付通道**:顾客在收银台选支付方式 → 真实支付 → 回调自动发货。

### 1.2 范围(最终确认)

**支付网关核心:**
- PaymentDriver 接口(pay/verifyCallback/getConfigFields/getInfo)
- PaymentService(createPayment/handleCallback/getEnabledChannels)
- PaymentResult(redirect/qrcode/form 三种返回)

**6 个内置 Driver:**
| Driver | code | 支付方式 |
|---|---|---|
| AlipayDriver | alipay | 支付宝网页支付(跳转) |
| WechatPayDriver | wechatpay | 微信 Native 扫码(qrcode) |
| UsdtDriver | usdt | USDT-TRC20(qrcode 钱包地址) |
| CodePayDriver | codepay | 码支付/免签聚合(跳转) |
| PaypalDriver | paypal | PayPal(跳转) |
| StripeDriver | stripe | Stripe Checkout(跳转) |

**后台:**
- PaymentChannelResource:卡片网格布局(图标/名称/描述/启用状态/配置 Modal)
- 配置 Modal:动态字段(由 Driver::getConfigFields() 驱动)
- 启用/停用/排序

**前台:**
- 支付页改造:展示已启用通道列表 → 顾客选通道 → 调 createPayment → 按 PaymentResult 类型渲染(跳转/二维码/表单)

**API:**
- POST /api/payments/create(发起支付)
- POST /api/payments/callback/{channel}(支付回调)
- GET /api/payments/channels(可用通道列表)

### 1.3 不含

- 主动回查(queryPayment)— 增强项,P1-D 先用回调;回查留后续
- 退款逻辑 — 后续
- 充值(余额充值)— 后续
- 支付通道的插件化安装/卸载 — P1-D 全部内置(硬编码 Driver 列表),插件式留 Phase 2

---

## 2. 决策记录

| # | 决策 | 选择 |
|---|---|---|
| D1 | 通道范围 | 支付宝+微信+USDT+码支付+PayPal+Stripe(全选) |
| D2 | 后台布局 | 卡片网格(方案 A,类 acg-faka 插件商店) |
| D3 | 配置表单 | 动态字段 Modal(由 Driver::getConfigFields() 驱动) |
| D4 | 架构 | API-first:PaymentService 为核心,前台 API + 后台 Filament 都调 |
| D5 | Driver 接口 | pay/verifyCallback/getConfigFields/getInfo 四方法 |
| D6 | PaymentResult | 三类型:redirect(跳转)/qrcode(扫码)/form(POST表单) |
| D7 | 回调驱动 | 支付回调 → verifyCallback → markPaid → 自动发货(P1-C 已就位) |

---

## 3. 数据模型

### 3.1 payment_channels 表(新建)

```
payment_channels:
  id
  merchant_id     FK→merchants(Phase 3 多商户,P1-D 写死 1)
  name            VARCHAR(60)    显示名(如"支付宝")
  code            VARCHAR(30)    编码(如 alipay/wechatpay/usdt)
  driver          VARCHAR(100)   Driver 类名(如 AlipayDriver)
  config          JSON           各通道配置(app_id/key/secret 等)
  fee             DECIMAL(5,4)   费率(如 0.006 = 0.6%)
  fee_type        VARCHAR(10)    percent|fixed
  sort            INT            排序
  enabled         TINYINT(1)     启用
  timestamps
  UNIQUE(merchant_id, code)
```

### 3.2 payments 表(复用 Phase 0,无变更)

已有字段够用:order_id, channel, channel_order_no, amount, status, paid_at, raw。
- `channel` 存 payment_channel 的 code(如 alipay)
- `raw` 存回调原文(JSON)
- `status`:pending/success/failed

### 3.3 内置通道种子(Seeder/Install)

P1-D 初始化时创建 6 条 payment_channels 记录(enabled=false,config=null),店主在后台逐个配置启用。

---

## 4. Driver 接口与实现

### 4.1 PaymentDriver 接口(`app/Payment/Contracts/PaymentDriver.php`)

```php
interface PaymentDriver
{
    // 发起支付
    public function pay(Order $order, array $config): PaymentResult;

    // 验证回调签名,返回 ['order_no' => ..., 'amount' => ...] 或 null
    public function verifyCallback(Request $request, array $config): ?array;

    // 配置字段定义(供后台 Modal 动态渲染)
    public function getConfigFields(): array;

    // 通道信息
    public function getInfo(): array;
}
```

### 4.2 getConfigFields() 返回结构

```php
[
    ['name' => 'app_id', 'label' => '应用 App ID', 'type' => 'text', 'required' => true],
    ['name' => 'public_key', 'label' => '支付宝公钥', 'type' => 'textarea', 'required' => true],
    ['name' => 'private_key', 'label' => '应用私钥', 'type' => 'textarea', 'required' => true],
]
```
type: text/textarea/password/number/select

### 4.3 PaymentResult(`app/Payment/PaymentResult.php`)

```php
class PaymentResult
{
    const TYPE_REDIRECT = 'redirect';
    const TYPE_QRCODE = 'qrcode';
    const TYPE_FORM = 'form';

    public string $type;
    public ?string $redirectUrl = null;
    public ?string $qrcodeContent = null;  // 二维码内容(URL/钱包地址)
    public ?string $formHtml = null;
}
```

### 4.4 各 Driver 的 getConfigFields

| Driver | 字段 |
|---|---|
| AlipayDriver | app_id, public_key, private_key |
| WechatPayDriver | mch_id, app_id, api_v3_key, private_cert, platform_cert |
| UsdtDriver | wallet_address, api_key, rate(汇率), expire_minutes |
| CodePayDriver | pid, key, api_url |
| PaypalDriver | client_id, client_secret, mode(sandbox/live) |
| StripeDriver | secret_key, webhook_secret |

---

## 5. PaymentService(`app/Support/PaymentService.php`)

```php
class PaymentService
{
    // 取已启用的通道列表(前台收银台用)
    public function getEnabledChannels(): Collection

    // 取所有通道(后台用)
    public function getAllChannels(): Collection

    // 发起支付
    public function createPayment(Order $order, int $channelId): array
    // 实例化 Driver → driver.pay() → 写 payments 表 → 返回 PaymentResult

    // 处理回调
    public function handleCallback(string $channelCode, Request $request): string
    // 找 channel → driver.verifyCallback() → 校验金额 → markPaid → 返回 "success"

    // 实例化 Driver(根据 channel.driver 类名)
    private function resolveDriver(PaymentChannel $channel): PaymentDriver

    // 保存通道配置(后台)
    public function saveChannelConfig(int $channelId, array $config): void

    // 启用/停用
    public function toggleChannel(int $channelId, bool $enabled): void
}
```

**createPayment 流程:**
```
channel = PaymentChannel::find(channelId)
driver = resolveDriver(channel)
result = driver.pay(order, channel.config)
payment = Payment::create([order_id, channel=channel.code, amount=order.amount, status=pending])
return [payment_id, result.type, result.redirectUrl/qrcodeContent/formHtml]
```

**handleCallback 流程:**
```
channel = PaymentChannel::where(code, channelCode).first()
driver = resolveDriver(channel)
data = driver.verifyCallback(request, channel.config)  // 验签
if (!data) return "fail"
order = Order::where(order_no, data.order_no)
if (order.amount != data.amount) return "fail"  // 金额校验
OrderService::markPaid(order.order_no)  // 状态机 + 发货(P1-C)
return "success"
```

---

## 6. API 接入层

```
GET  /api/payments/channels          可用通道列表(不需 auth,游客用)
POST /api/payments/create            发起支付(body: order_no, channel_id)
POST /api/payments/callback/{channel} 支付回调(支付平台调,不需 auth)
```

**Controller**:`app/Http/Controllers/Api/PaymentController.php`,调 PaymentService。

**注意**:
- `/channels` 和 `/create` 不需 auth(游客)
- `/callback/{channel}` 必须不需 auth(支付平台服务器调),但靠 Driver::verifyCallback 验签保证安全
- callback 路由需排除 CSRF 中间件

---

## 7. 前台支付页改造

P1-C 的 Pay.vue 改为:

1. 进入支付页 → GET /api/payments/channels → 展示已启用通道(图标+名称按钮)
2. 顾客点某通道 → POST /api/payments/create → 返回 PaymentResult
3. 按 type 渲染:
   - redirect: `window.location.href = result.redirectUrl`
   - qrcode: 页面显示二维码(用 qrcode 库渲染 result.qrcodeContent)+ "扫码后自动跳转"
   - form: 提交 formHtml(自动 POST 到支付平台)
4. 支付完成 → 支付平台回调 → markPaid → 顾客在订单查询页看到卡密

**兼容**:保留 mock-pay 端点(P1-C 已有),开发期/无通道时仍可模拟支付。

---

## 8. 后台 PaymentChannelResource

### 8.1 卡片网格布局

不用标准 Filament Table,用自定义 Page 渲染卡片网格(类似 acg-faka 插件商店):
- 每通道一张卡:图标(Driver::getInfo icon)+ 名称 + 描述 + 启用状态 Toggle + "配置"按钮
- "配置"按钮 → 弹 Modal(动态字段)
- 可拖拽排序

### 8.2 配置 Modal

- 按 Driver::getConfigFields() 动态生成表单字段(text/textarea/password/number/select)
- 底部显示回调地址(`/api/payments/callback/{code}`),方便店主填到支付平台
- 保存调 PaymentService::saveChannelConfig

### 8.3 初始化(Seeder)

6 条 payment_channels 记录(enabled=false, config=null)。

---

## 9. P1-D 验收清单

**Driver 抽象:**
- [ ] PaymentDriver 接口
- [ ] PaymentResult
- [ ] 6 个 Driver 实现(Alipay/WechatPay/Usdt/CodePay/Paypal/Stripe)

**Service + 数据:**
- [ ] payment_channels 表 + 迁移
- [ ] PaymentService(createPayment/handleCallback/getEnabledChannels/saveChannelConfig/toggleChannel)
- [ ] 6 通道 Seeder 初始化

**API:**
- [ ] GET /api/payments/channels
- [ ] POST /api/payments/create
- [ ] POST /api/payments/callback/{channel}(回调,免 auth + 验签)

**后台:**
- [ ] PaymentChannelResource 卡片网格
- [ ] 配置 Modal(动态字段)
- [ ] 启用/停用/排序

**前台:**
- [ ] 支付页改造(通道选择 + redirect/qrcode/form 渲染)
- [ ] 保留 mock-pay(开发备用)

**端到端(至少 1 个通道真实跑通):**
- [ ] 配置某通道(如码支付测试参数)→ 收银台选通道 → 发起支付 → 回调 → 发货

**通用:**
- [ ] docs/ 不进 git
- [ ] 测试通过

---

## 10. 风险与对策

| 风险 | 对策 |
|---|---|
| 回调丢失(网络延迟) | P1-D 先用回调;回查(queryPayment)留后续增强 |
| 支付宝/微信需企业资质 | 开发测试用沙箱;USDT/码支付无门槛,优先实测 |
| 回调安全 | Driver::verifyCallback 验签(每通道不同签名方式) + 金额校验 |
| 并发回调(重复通知) | markPaid 检查 status=pending(P1-C 已防重复支付) |
| Stripe webhook 签名 | StripeDriver 用 Stripe-Signature header 验签 |
| 通道配置敏感信息 | config JSON 存 DB,密码类字段前端显示 ***;考虑加密存储(后续) |

---

## 11. Open Questions(无)

brainstorming 阶段所有决策已确认(§2)。复查发现 3 点已修正:payment_channels 加 merchant_id、回查标为增强项、前台选通道交互明确(§7)。

---

*本 spec 为活文档,实现中如有偏差回填。*
