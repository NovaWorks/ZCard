# ZCard P1-C — 订单核心 + 收银台 设计（Spec）

> Phase 1 第三个子项目。把商品(P1-A)+卡密(P1-B)串成完整交易闭环:下单→锁卡→支付→发货→查询。
> 本文档不进 git（`.gitignore` 忽略整个 `docs/`）。

- **日期**:2026-07-29
- **范围**:P1-C(订单引擎 + 自动发货 + 收银台 + 订单查询 + 后台订单管理)
- **状态**:待实现
- **对应计划**:`acg-faka/开发计划.md` Phase 1 的"订单系统/自动发货/收银台"部分

---

## 1. 定位与范围

### 1.1 P1-C 是什么

P1-C = **订单交易闭环**。商品和卡密已就位,现在把购买流程跑通:顾客下单(锁卡)→ 支付 → 自动发货(按 delivery_mode)→ 订单查询。P1-C 完成后,发卡系统的核心交易链路端到端打通。

### 1.2 范围(最终确认)

**订单引擎(Service 层,API-first):**
- OrderService:createOrder(锁卡→建订单 pending)、markPaid(状态机 pending→paid,触发事件)、closeExpired(超时关单释放卡密)、queryOrder、getOrderDetail
- DeliveryService:监听 OrderPaid 事件,按 delivery_mode 发货(写 order_deliveries 快照,卡密 used/删除)
- 超时关单:Laravel Scheduler 每5分钟扫 pending,超 N 分钟(默认15,可配)关闭释放
- OrderPaid 事件

**收银台(前台):**
- 详情页"立即购买"带 SKU+数量跳收银台
- 收银台:确认商品/SKU/数量/价格 → 填邮箱/手机 + 查询密码(开关) + 人机验证(开关) → 提交建单 → 跳支付
- 模拟支付(P1-C 测试用):mock-pay 端点直接 markPaid,P1-D 替换为真实支付回调
- 支付成功展示卡密(order_deliveries 明文 + 复制)

**订单查询(前台):**
- 查询页:邮箱/手机 + 订单号(+查询密码,按开关) → 订单状态 + 已发货卡密

**后台:**
- OrderResource:订单列表/状态筛选/查看详情(含发货卡密)/手动关闭

### 1.3 不含

- 真实支付通道(微信/支付宝)→ P1-D
- 优惠券/秒杀/分销/会员等级消费 → Phase 3
- 邮件通知(发卡邮件)→ 后续子项
- 真实评价系统 → 后续子项

---

## 2. 决策记录(来自 brainstorming)

| # | 决策 | 选择 |
|---|---|---|
| D1 | 下单身份 | 游客下单(不需登录),填邮箱/手机 |
| D2 | 超时关单 | 定时任务(Scheduler 每5分钟,超时默认15分钟可配),释放锁定卡密 |
| D3 | 收银台范围 | 完整收银台页(P1-C 交付) |
| D4 | 订单查询 | 前台查询页(邮箱+订单号/密码) |
| D5 | 发货模式 | 按 product.delivery_mode(status/delete),写 order_deliveries 快照 |
| D6 | 架构 | API-first:Service 为核心,前台 API + 后台 Filament 都调 |
| D7 | 模拟支付 | P1-C 提供 mock-pay 端点方便端到端测试,P1-D 替换 |

---

## 3. 订单状态机

```
[pending] --支付成功(markPaid)--> [paid] --(DeliveryService 发货完成)--> (终态)
    |
    +--超时未付(closeExpired)--> [closed] (锁定卡密释放回 unused)
    |
    +--退款--> [refunded] (P1-C 结构预留,不实现逻辑)
```

**状态说明:**
- `pending`:已下单,卡密已锁定(locked),等待支付
- `paid`:已支付,自动发货完成,卡密已交付(used/删除),order_deliveries 快照已写
- `closed`:超时未付已关闭,卡密已释放(unused)
- `refunded`:退款(P1-C 不实现,字段就位)

**状态流转规则:**
- pending → paid:仅通过 markPaid(支付成功)
- pending → closed:仅通过 closeExpired(超时)或后台手动关闭
- paid/closed → refunded:后续
- 状态变更用 DB 事务 + lockForUpdate 防并发

---

## 4. Service 层(API-first 核心)

### 4.1 OrderService(`app/Support/OrderService.php`)

```php
class OrderService
{
    // 创建订单(锁卡 → 建 order pending)
    // 抛 InsufficientStockException 当库存不足
    public function createOrder(int $productId, ?int $skuId, int $qty, array $customer): Order

    // 标记支付成功(状态机 pending→paid,fire OrderPaid 事件)
    // 抛异常当订单非 pending(防重复支付)
    public function markPaid(string $orderNo): Order

    // 超时关单(Scheduler 调),返回关闭数
    public function closeExpired(): int

    // 查询订单(凭 contact + orderNo,可选 password)
    public function queryOrder(string $contact, string $orderNo, ?string $password = null): ?Order

    // 订单详情(含发货卡密)
    public function getOrderDetail(Order $order): array

    // 后台手动关闭订单
    public function closeOrder(int $orderId): Order
}
```

**createOrder 关键逻辑(锁卡防超卖):**
```php
DB::transaction {
    $cards = Card::where('product_id', $productId)
        ->where('status', Card::STATUS_UNUSED)
        ->lockForUpdate()
        ->limit($qty)
        ->get();
    if ($cards->count() < $qty) throw new InsufficientStockException;
    $cards->each->update([status=locked, locked_at=now(), order_id=$order->id]);
    $order = Order::create([order_no=生成, ...status=pending, contact, password=加密, extra]);
}
```

**markPaid:**
```php
DB::transaction {
    $order = Order::where(order_no)->lockForUpdate()->first();
    if ($order->status !== 'pending') throw new InvalidOrderStatusException;
    $order->update([status=paid, paid_at=now()]);
}
event(new OrderPaid($order));  // DeliveryService 监听
```

**closeExpired:**
```php
$timeout = StorefrontConfig::get('order_close_minutes') ?? 15;
$expired = Order::where(status=pending)->where(created_at < now()-$timeout分钟)->get();
foreach ($expired as $order) {
    $order->update([status=closed, closed_at=now()]);
    Card::where(order_id, $order->id)->where(status=locked)
        ->update([status=unused, locked_at=null, order_id=null]);
}
return $expired->count();
```

**queryOrder 验证逻辑:**
- 必须匹配 contact(邮箱/手机) + orderNo
- 若 `order_query_password` 开关开,还需验证 password(用 Hash::check)
- 返回订单(含 order_deliveries 卡密)

### 4.2 DeliveryService(`app/Support/DeliveryService.php`)

```php
class DeliveryService
{
    // 监听 OrderPaid 事件(EventServiceProvider 注册)
    public function handleOrderPaid(OrderPaid $event): void
    {
        $this->deliver($event->order);
    }

    // 发货(按 delivery_mode)
    public function deliver(Order $order): void
    {
        $product = $order->product;
        $mode = $product->delivery_mode; // status | delete
        $cards = Card::where(order_id, $order->id)->get();

        foreach ($cards as $card) {
            // 写发货快照(明文)
            OrderDelivery::create([
                order_id, product_id,
                card_content => $card->plainContent(),
                delivered_mode => $mode,
                delivered_at => now(),
            ]);
            // 按模式处理
            if ($mode === 'delete') {
                $card->delete();
            } else {
                $card->update([status=used, used_at=now()]);
            }
        }
    }
}
```

### 4.3 OrderPaid 事件

`app/Events/OrderPaid.php`:简单事件,携带 Order。
在 `EventServiceProvider` 注册 `OrderPaid::class => [DeliveryService::class]`。

---

## 5. 配置项(新增 settings)

| key | 默认 | 说明 |
|---|---|---|
| `storefront.order_close_minutes` | 15 | 订单超时关闭分钟数 |
| `storefront.contact_type` | email | 收货联系方式(email/phone) |

> `order_query_password` / `trade_captcha` 已在 P1-A 就位。

在 StorefrontConfig::defaults() 加这两个 key。

---

## 6. 超时关单定时任务

`app/Console/Commands/CloseExpiredOrders.php`(artisan 命令):
- 调 `OrderService::closeExpired()`
- 在 `routes/console.php` 或 `ConsoleKernel` 注册 Scheduler:每5分钟跑
  ```php
  Schedule::command('orders:close-expired')->everyFiveMinutes();
  ```

---

## 7. 图形验证码(mews/captcha)

人机验证用 `mews/captcha` 包:
- 安装:`composer require mews/captcha`
- 配置:发布配置,生成验证码路由
- 收银台展示验证码图片(`<img src="{captcha_url}">`)+ 输入框
- API 校验:创建订单时校验 captcha(受 `trade_captcha` 开关控制)
- 验证码图片路由需排除 CSRF/API 中间件

---

## 8. API 接入层(routes/api.php)

```
POST /api/orders                 创建订单(游客)
  body: product_id, sku_id?, qty, contact, password?, captcha?, extra?
  返回: {order_no, amount, status}

POST /api/orders/{orderNo}/mock-pay  模拟支付回调(P1-C 测试,P1-D 替换)
  返回: {order_no, status=paid, delivered: true}

GET  /api/orders/query            查询订单
  参数: contact, order_no, password?
  返回: 订单详情(含卡密) 或 404

GET  /api/orders/{orderNo}        订单详情(需 contact 验证,header 或参数)
```

**Controller**:`app/Http/Controllers/Api/OrderController.php`,调 OrderService。

**注意**:创建订单 API 不需 auth(游客),但需验证码校验(若开关开)。订单查询 API 也不需 auth(凭 contact+orderNo)。

---

## 9. 前台页面(Vue)

### 9.1 收银台 `/checkout`

- query: `product`(slug 或 id)、`sku`(id)、`qty`
- 展示:商品确认(封面/名/SKU/数量/小计)
- 表单:邮箱(contact,必填)+ 查询密码(若开关)+ 人机验证(若开关)
- 提交:`POST /api/orders` → 成功跳 `/pay/:orderNo`
- 错误:库存不足/验证码错 → 提示

### 9.2 支付页 `/pay/:orderNo`

- 展示:订单号 + 金额 + 状态
- 模拟支付按钮 → `POST /api/orders/:orderNo/mock-pay` → 成功展示卡密
- (P1-D 替换为真实支付)

### 9.3 订单查询 `/orders/query`

- 表单:邮箱/手机 + 订单号(+查询密码若开关)
- 查询:`GET /api/orders/query` → 展示订单状态 + 卡密(可复制)

### 9.4 路由更新(storefront router)

新增:
- `/checkout` → Checkout.vue
- `/pay/:orderNo` → Pay.vue
- `/orders/query` → OrderQuery.vue
- `/orders/:orderNo` → OrderDetail.vue

### 9.5 Header 加"订单查询"入口

AppHeader 加"订单查询"链接。

---

## 10. 后台(Filament)

### OrderResource

- 列表:order_no / product.name / quantity / amount(元) / status(badge) / contact / created_at
- 状态筛选:pending/paid/closed/refunded
- 详情:含 order_deliveries 发货卡密(明文)
- 操作:手动关闭订单(调 OrderService::closeOrder)
- navigationGroup: 交易(新建分组)
- 中文化标签

---

## 11. P1-C 验收清单

**订单引擎:**
- [ ] OrderService(createOrder 锁卡防超卖 / markPaid 状态机 / closeExpired / queryOrder / closeOrder)
- [ ] DeliveryService(监听 OrderPaid,按 delivery_mode 发货,写快照)
- [ ] OrderPaid 事件 + EventServiceProvider 注册
- [ ] closeExpired artisan 命令 + Scheduler 每5分钟

**配置:**
- [ ] StorefrontConfig 加 order_close_minutes / contact_type
- [ ] mews/captcha 安装 + 配置

**API:**
- [ ] POST /api/orders(游客创建,验证码校验)
- [ ] POST /api/orders/{orderNo}/mock-pay(模拟支付)
- [ ] GET /api/orders/query(查询)
- [ ] Controller 调 OrderService(API-first)

**前台:**
- [ ] 收银台页(确认商品/填信息/验证码/提交)
- [ ] 支付页(模拟支付/展示卡密)
- [ ] 订单查询页(邮箱+订单号/密码)
- [ ] Header 加订单查询入口

**后台:**
- [ ] OrderResource(列表/筛选/详情/手动关闭)

**端到端:**
- [ ] 完整流程:详情页→收银台→下单→模拟支付→发货→订单查询 端到端通
- [ ] 超时关单:pending 超15分钟→closed→卡密释放
- [ ] 防超卖:库存不足时下单失败

**通用:**
- [ ] docs/ 不进 git
- [ ] 测试通过

---

## 12. 风险与对策

| 风险 | 对策 |
|---|---|
| 并发超卖(多订单抢同一卡密) | lockForUpdate + DB 事务,锁定后再建单 |
| 重复支付(订单已 paid 再回调) | markPaid 检查 status=pending,非 pending 抛异常 |
| 超时关单与支付回调并发 | 都用 lockForUpdate + 事务;先关单则支付回调失败(提示订单已关闭) |
| 发货失败(卡密解密错) | deliver 用事务,失败回滚;记录日志 |
| 验证码 API 无 session | mews/captcha 用 session,API 需配置 session driver;或用无状态验证码方案 |
| 游客订单无 auth 保护 | 查询需 contact+orderNo 双因子;卡密查询页可加限流 |

---

## 13. Open Questions(无)

brainstorming 阶段所有决策已确认(§2)。无遗留。

---

*本 spec 为活文档,实现中如有偏差回填。*
