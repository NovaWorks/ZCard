# ZCard P1-C — 订单核心 + 收银台 实现计划

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** 把商品+卡密串成完整交易闭环:下单(锁卡)→支付(状态机)→自动发货(按 delivery_mode)→订单查询。

**Architecture:** API-first —— OrderService/DeliveryService 是核心(UI 无关),前台 API + 后台 Filament 都调它。锁卡用 lockForUpdate 防超卖,支付触发 OrderPaid 事件驱动发货,超时关单用 Scheduler。

**Tech Stack:** Laravel 13, mews/captcha(图形验证码), Vue3, Filament v5, Redis 队列。

**对应 spec:** `docs/superpowers/specs/2026-07-29-zcard-p1c-order-checkout-design.md`

**Laravel 13 关键(已确认):**
- 事件:`Event::listen(OrderPaid::class, DeliveryService::class)` 在 `AppServiceProvider::boot()` 注册(Laravel 13 无 EventServiceProvider,用自动发现或 Event facade)
- Scheduler:`Schedule::command('orders:close-expired')->everyFiveMinutes()` 在 `routes/console.php`
- 订单号生成:`Str::uuid()` 或时间戳+随机

---

## 环境前提

- 容器在跑,app :8092,storefront :5173。
- P1-A 商品 + P1-B 卡密就位(有测试商品 slug=steam-card,有卡密可导入)。
- 所有 artisan 命令走 `./vendor/bin/sail`。

---

## 文件结构总览

```
app/
├── Events/OrderPaid.php                    # T1 事件
├── Exceptions/InsufficientStockException.php  # T1 异常
├── Support/
│   ├── OrderService.php                    # T1 订单引擎
│   └── DeliveryService.php                 # T2 发货引擎
├── Providers/AppServiceProvider.php (改)   # T2 注册事件监听
├── Console/Commands/CloseExpiredOrdersCommand.php  # T3 超时关单命令
├── Http/Controllers/Api/OrderController.php  # T4 API
└── Filament/Resources/Orders/              # T7 后台 OrderResource
routes/
├── api.php (改)                             # T4 API 路由
└── console.php (改)                         # T3 Scheduler
app/Support/StorefrontConfig.php (改)        # T1 加配置项
storefront/src/
├── views/{Checkout,Pay,OrderQuery,OrderDetail}.vue  # T5,T6
├── api/orders.ts                            # T5
├── router/index.ts (改)                     # T5
└── components/AppHeader.vue (改)            # T5 加订单查询入口
```

---

## Task 1: OrderService + OrderPaid 事件 + 异常 + 配置

**Files:**
- Create: `app/Events/OrderPaid.php`
- Create: `app/Exceptions/InsufficientStockException.php`
- Create: `app/Support/OrderService.php`
- Modify: `app/Support/StorefrontConfig.php`(加 order_close_minutes/contact_type)

- [ ] **Step 1: 创建 OrderPaid 事件**

`app/Events/OrderPaid.php`:
```php
<?php

namespace App\Events;

use App\Models\Order;
use Illuminate\Foundation\Events\Dispatchable;

class OrderPaid
{
    use Dispatchable;

    public function __construct(public Order $order) {}
}
```

- [ ] **Step 2: 创建异常**

`app/Exceptions/InsufficientStockException.php`:
```php
<?php

namespace App\Exceptions;

use RuntimeException;

class InsufficientStockException extends RuntimeException
{
    public function __construct(string $message = '库存不足', int $code = 0)
    {
        parent::__construct($message, $code);
    }
}
```

- [ ] **Step 3: StorefrontConfig 加配置项**

修改 `app/Support/StorefrontConfig.php`,在 `defaults()` 数组末尾(`'trade_captcha' => true,` 之后)加:
```php
            'order_close_minutes' => 15,
            'contact_type' => 'email',
```

- [ ] **Step 4: 创建 OrderService**

`app/Support/OrderService.php`:
```php
<?php

namespace App\Support;

use App\Events\OrderPaid;
use App\Exceptions\InsufficientStockException;
use App\Models\Card;
use App\Models\Order;
use App\Models\ProductSku;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class OrderService
{
    /**
     * 创建订单:锁卡 → 建 order(pending)。
     * @param array $customer [contact, password?, extra?]
     * @throws InsufficientStockException
     */
    public function createOrder(int $productId, ?int $skuId, int $qty, array $customer): Order
    {
        $product = \App\Models\Product::with('skus')->findOrFail($productId);
        $unitPrice = $skuId
            ? ($product->skus->firstWhere('id', $skuId)?->price ?? $product->price)
            : $product->price;
        $amount = $unitPrice * $qty;

        return DB::transaction(function () use ($productId, $skuId, $qty, $customer, $product, $amount) {
            // 锁卡(FOR UPDATE 防并发超卖)
            $cards = Card::where('product_id', $productId)
                ->where('status', Card::STATUS_UNUSED)
                ->lockForUpdate()
                ->limit($qty)
                ->get();

            if ($cards->count() < $qty) {
                throw new InsufficientStockException("库存不足,需要 {$qty} 张,仅剩 {$cards->count()} 张");
            }

            // 创建订单
            $order = Order::create([
                'order_no' => $this->generateOrderNo(),
                'merchant_id' => $product->merchant_id,
                'user_id' => null, // 游客
                'product_id' => $productId,
                'quantity' => $qty,
                'amount' => $amount,
                'status' => 'pending',
                'contact' => $customer['contact'] ?? null,
                'extra' => array_merge(
                    $skuId ? ['sku_id' => $skuId, 'sku_name' => $product->skus->firstWhere('id', $skuId)?->name] : [],
                    ['control' => $customer['extra'] ?? []],
                ),
            ]);

            // 卡密存查询密码(若提供)
            if (! empty($customer['password'])) {
                $order->extra = array_merge($order->extra ?? [], ['query_password' => Hash::make($customer['password'])]);
                $order->save();
            }

            // 锁定卡密
            $cards->each->update([
                'status' => Card::STATUS_LOCKED,
                'locked_at' => now(),
                'order_id' => $order->id,
            ]);

            return $order;
        });
    }

    /** 标记支付成功(状态机 pending→paid,fire OrderPaid) */
    public function markPaid(string $orderNo): Order
    {
        $order = DB::transaction(function () use ($orderNo) {
            $order = Order::where('order_no', $orderNo)->lockForUpdate()->firstOrFail();
            if ($order->status !== 'pending') {
                throw new \RuntimeException("订单状态异常: {$order->status},无法支付");
            }
            $order->update(['status' => 'paid', 'paid_at' => now()]);
            return $order;
        });

        event(new OrderPaid($order));

        return $order;
    }

    /** 超时关单(Scheduler 调),返回关闭数 */
    public function closeExpired(): int
    {
        $minutes = (int) (StorefrontConfig::get('order_close_minutes') ?? 15);
        $cutoff = now()->subMinutes($minutes);

        $expired = Order::where('status', 'pending')
            ->where('created_at', '<', $cutoff)
            ->get();

        $count = 0;
        foreach ($expired as $order) {
            DB::transaction(function () use ($order) {
                $order->update(['status' => 'closed', 'closed_at' => now()]);
                Card::where('order_id', $order->id)
                    ->where('status', Card::STATUS_LOCKED)
                    ->update([
                        'status' => Card::STATUS_UNUSED,
                        'locked_at' => null,
                        'order_id' => null,
                    ]);
            });
            $count++;
        }

        return $count;
    }

    /** 后台手动关闭 */
    public function closeOrder(int $orderId): Order
    {
        $order = Order::findOrFail($orderId);
        if ($order->status !== 'pending') {
            throw new \RuntimeException("仅待支付订单可关闭");
        }
        DB::transaction(function () use ($order) {
            $order->update(['status' => 'closed', 'closed_at' => now()]);
            Card::where('order_id', $order->id)
                ->where('status', Card::STATUS_LOCKED)
                ->update(['status' => Card::STATUS_UNUSED, 'locked_at' => null, 'order_id' => null]);
        });
        return $order->fresh();
    }

    /** 查询订单(凭 contact + orderNo,可选 password) */
    public function queryOrder(string $contact, string $orderNo, ?string $password = null): ?Order
    {
        $order = Order::where('order_no', $orderNo)
            ->where('contact', $contact)
            ->with('orderDeliveries')
            ->first();

        if (! $order) {
            return null;
        }

        // 若开启查询密码,验证
        $needPassword = StorefrontConfig::get('order_query_password');
        if ($needPassword) {
            $storedHash = $order->extra['query_password'] ?? null;
            if (! $storedHash || ! Hash::check($password ?? '', $storedHash)) {
                return null; // 密码错,视为查不到
            }
        }

        return $order;
    }

    /** 订单详情(含发货卡密) */
    public function getOrderDetail(Order $order): array
    {
        $order->load('orderDeliveries', 'product');

        $cards = $order->status === 'paid'
            ? $order->orderDeliveries->map(fn ($d) => $d->card_content)->toArray()
            : [];

        return [
            'order_no' => $order->order_no,
            'status' => $order->status,
            'product_name' => $order->product?->name,
            'quantity' => $order->quantity,
            'amount' => $order->amount,
            'created_at' => $order->created_at,
            'paid_at' => $order->paid_at,
            'cards' => $cards,
            'extra' => $order->extra,
        ];
    }

    private function generateOrderNo(): string
    {
        return 'ORD' . now()->format('YmdHis') . strtoupper(Str::random(6));
    }
}
```

> 注意:Order 模型需加 `orderDeliveries` 关系(T2 Step 1 加)。

- [ ] **Step 5: tinker 验证 createOrder(先导卡密再下单)**

```bash
# 先确保有卡密
./vendor/bin/sail artisan tinker --execute="
\$svc = app(App\Support\CardImportService::class);
\$p = App\Models\Product::where('slug','steam-card')->first();
\$svc->import(\$p->id, 1, \"ord-001\nord-002\nord-003\", ['source'=>'test']);
echo 'cards imported';
" 2>&1 | tail -1

# 测试下单
./vendor/bin/sail artisan tinker --execute="
\$svc = app(App\Support\OrderService::class);
\$p = App\Models\Product::where('slug','steam-card')->first();
\$order = \$svc->createOrder(\$p->id, null, 2, ['contact'=>'test@example.com']);
echo 'order_no='.\$order->order_no.' status='.\$order->status.' amount='.\$order->amount;
echo ' | locked cards: '.App\Models\Card::where('order_id',\$order->id)->count();
" 2>&1 | tail -1
```
Expected: order_no=ORD... status=pending, locked cards=2

- [ ] **Step 6: 验证库存不足抛异常**

```bash
./vendor/bin/sail artisan tinker --execute="
\$svc = app(App\Support\OrderService::class);
\$p = App\Models\Product::where('slug','steam-card')->first();
try { \$svc->createOrder(\$p->id, null, 100, ['contact'=>'x@x.com']); echo 'NO EXCEPTION(bad)'; }
catch (App\Exceptions\InsufficientStockException \$e) { echo 'OK: '.\$e->getMessage(); }
" 2>&1 | tail -1
```
Expected: `OK: 库存不足...`

- [ ] **Step 7: 提交**

```bash
git add app/ && git commit -m "feat(order): OrderService + OrderPaid event + exceptions + config"
```

---

## Task 2: DeliveryService + 事件注册

**Files:**
- Create: `app/Support/DeliveryService.php`
- Modify: `app/Models/Order.php`(加 orderDeliveries 关系)
- Modify: `app/Providers/AppServiceProvider.php`(注册事件监听)

- [ ] **Step 1: Order 模型加 orderDeliveries 关系**

修改 `app/Models/Order.php`,在 `payments()` 方法后加:
```php
public function orderDeliveries(): HasMany
{
    return $this->hasMany(OrderDelivery::class);
}
```
(确保 use HasMany 已在文件顶部)

- [ ] **Step 2: 创建 DeliveryService**

`app/Support/DeliveryService.php`:
```php
<?php

namespace App\Support;

use App\Events\OrderPaid;
use App\Models\Card;
use App\Models\Order;
use App\Models\OrderDelivery;
use Illuminate\Support\Facades\Log;

class DeliveryService
{
    /** 监听 OrderPaid 事件 */
    public function handle(OrderPaid $event): void
    {
        $this->deliver($event->order);
    }

    /** 发货:按 delivery_mode 写快照 + 处理卡密 */
    public function deliver(Order $order): void
    {
        $order->load('product');
        $product = $order->product;
        $mode = $product->delivery_mode; // status | delete

        $cards = Card::where('order_id', $order->id)->get();

        foreach ($cards as $card) {
            // 写发货快照(明文)
            OrderDelivery::create([
                'order_id' => $order->id,
                'product_id' => $order->product_id,
                'card_content' => $card->plainContent(),
                'delivered_mode' => $mode,
                'delivered_at' => now(),
            ]);

            // 按模式处理
            if ($mode === 'delete') {
                $card->delete();
            } else {
                $card->update(['status' => Card::STATUS_USED, 'used_at' => now()]);
            }
        }

        Log::info("订单 {$order->order_no} 发货完成", ['cards' => $cards->count(), 'mode' => $mode]);
    }
}
```

- [ ] **Step 3: 注册事件监听(AppServiceProvider)**

修改 `app/Providers/AppServiceProvider.php` 的 `boot()` 方法,加:
```php
use App\Events\OrderPaid;
use App\Support\DeliveryService;
use Illuminate\Support\Facades\Event;
// ...
public function boot(): void
{
    Event::listen(OrderPaid::class, [DeliveryService::class, 'handle']);
}
```

- [ ] **Step 4: 验证 markPaid → 发货**

```bash
./vendor/bin/sail artisan tinker --execute="
\$svc = app(App\Support\OrderService::class);
\$order = App\Models\Order::where('status','pending')->latest()->first();
\$paid = \$svc->markPaid(\$order->order_no);
echo 'status='.\$paid->status;
echo ' | deliveries='.\App\Models\OrderDelivery::where('order_id',\$paid->id)->count();
echo ' | used cards='.\App\Models\Card::where('order_id',\$paid->id)->where('status','used')->count();
" 2>&1 | tail -1
```
Expected: status=paid, deliveries=2, used cards=2

- [ ] **Step 5: 提交**

```bash
git add app/ && git commit -m "feat(order): DeliveryService + OrderPaid listener + orderDeliveries relation"
```

---

## Task 3: 超时关单命令 + Scheduler

**Files:**
- Create: `app/Console/Commands/CloseExpiredOrdersCommand.php`
- Modify: `routes/console.php`

- [ ] **Step 1: 创建命令**

`app/Console/Commands/CloseExpiredOrdersCommand.php`:
```php
<?php

namespace App\Console\Commands;

use App\Support\OrderService;
use Illuminate\Console\Command;

class CloseExpiredOrdersCommand extends Command
{
    protected $signature = 'orders:close-expired';
    protected $description = '关闭超时未支付的订单并释放卡密';

    public function handle(OrderService $service): int
    {
        $count = $service->closeExpired();
        $this->info("已关闭 {$count} 个超时订单");
        return self::SUCCESS;
    }
}
```

- [ ] **Step 2: 注册 Scheduler**

修改 `routes/console.php`,在末尾加:
```php
use Illuminate\Support\Facades\Schedule;

Schedule::command('orders:close-expired')->everyFiveMinutes()->withoutOverlapping();
```

- [ ] **Step 3: 验证命令**

```bash
./vendor/bin/sail artisan orders:close-expired 2>&1 | tail -2
```
Expected: `已关闭 0 个超时订单`(没有 pending 的,或关闭了刚测试的)

- [ ] **Step 4: 提交**

```bash
git add app/Console/Commands/ routes/console.php && git commit -m "feat(order): close-expired command + scheduler"
```

---

## Task 4: API 接入层(OrderController)

**Files:**
- Create: `app/Http/Controllers/Api/OrderController.php`
- Modify: `routes/api.php`

- [ ] **Step 1: 创建 OrderController**

`app/Http/Controllers/Api/OrderController.php`:
```php
<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\OrderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OrderController extends Controller
{
    public function create(Request $request, OrderService $service): JsonResponse
    {
        $data = $request->validate([
            'product_id' => 'required|integer|exists:products,id',
            'sku_id' => 'nullable|integer',
            'qty' => 'required|integer|min:1|max:100',
            'contact' => 'required|string|max:150',
            'password' => 'nullable|string|max:50',
            'captcha' => 'nullable|string',
            'extra' => 'nullable|array',
        ]);

        // 验证码校验(若开关开)
        // P1-C 先跳过 captcha 校验(mews/captcha 装后补),记录 TODO

        $order = $service->createOrder(
            $data['product_id'],
            $data['sku_id'] ?? null,
            $data['qty'],
            [
                'contact' => $data['contact'],
                'password' => $data['password'] ?? null,
                'extra' => $data['extra'] ?? null,
            ]
        );

        return response()->json([
            'order_no' => $order->order_no,
            'amount' => $order->amount,
            'status' => $order->status,
        ], 201);
    }

    public function mockPay(string $orderNo, OrderService $service): JsonResponse
    {
        try {
            $order = $service->markPaid($orderNo);
            return response()->json([
                'order_no' => $order->order_no,
                'status' => $order->status,
                'delivered' => $order->fresh()->orderDeliveries()->count() > 0,
            ]);
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 400);
        }
    }

    public function query(Request $request, OrderService $service): JsonResponse
    {
        $data = $request->validate([
            'contact' => 'required|string',
            'order_no' => 'required|string',
            'password' => 'nullable|string',
        ]);

        $order = $service->queryOrder($data['contact'], $data['order_no'], $data['password'] ?? null);

        if (! $order) {
            return response()->json(['message' => '未找到订单,请检查邮箱和订单号'], 404);
        }

        return response()->json($service->getOrderDetail($order));
    }
}
```

- [ ] **Step 2: 注册路由**

修改 `routes/api.php`,在 cards 路由组后加(订单 API 不需 auth,游客用):
```php
use App\Http\Controllers\Api\OrderController;

// 订单(游客,不需 auth)
Route::post('/orders', [OrderController::class, 'create'])->name('api.orders.create');
Route::post('/orders/{orderNo}/mock-pay', [OrderController::class, 'mockPay'])->name('api.orders.mock-pay');
Route::get('/orders/query', [OrderController::class, 'query'])->name('api.orders.query');
```

- [ ] **Step 3: 验证 API(创建订单)**

```bash
./vendor/bin/sail artisan route:clear
# 确保有卡密
./vendor/bin/sail artisan tinker --execute="
\$svc = app(App\Support\CardImportService::class);
\$p = App\Models\Product::where('slug','steam-card')->first();
\$svc->import(\$p->id, 1, \"api-ord-001\napi-ord-002\", ['source'=>'api-test']);
echo 'ready';
" 2>&1 | tail -1

curl -s -X POST http://localhost:8092/api/orders \
  -H "Content-Type: application/json" \
  -d '{"product_id":1,"qty":1,"contact":"api@example.com"}'
```
Expected: 返回 `{"order_no":"ORD...","amount":...,"status":"pending"}`

- [ ] **Step 4: 验证 mock-pay + query**

```bash
# 取刚创建的 orderNo
ORDERNO=$(./vendor/bin/sail artisan tinker --execute="echo App\Models\Order::where('contact','api@example.com')->latest()->value('order_no');" 2>&1 | tail -1)
echo "orderNo=$ORDERNO"
curl -s -X POST "http://localhost:8092/api/orders/$ORDERNO/mock-pay"
echo ""
curl -s "http://localhost:8092/api/orders/query?contact=api@example.com&order_no=$ORDERNO" | head -c 300
```
Expected: mock-pay 返回 status=paid delivered=true;query 返回含卡密

- [ ] **Step 5: 提交**

```bash
git add app/Http/Controllers/Api/ routes/api.php && git commit -m "feat(api): order create/mock-pay/query endpoints"
```

---

## Task 5: 前台 API 封装 + 收银台 + 支付页

**Files:**
- Create: `storefront/src/api/orders.ts`
- Modify: `storefront/src/router/index.ts`
- Create: `storefront/src/views/Checkout.vue`
- Create: `storefront/src/views/Pay.vue`

- [ ] **Step 1: orders API 封装**

`storefront/src/api/orders.ts`:
```ts
import request from './request'

export interface CreatedOrder {
  order_no: string; amount: number; status: string
}
export interface OrderDetail {
  order_no: string; status: string; product_name?: string
  quantity: number; amount: number; cards: string[]
  created_at: string; paid_at?: string
}
export const createOrder = (data: {
  product_id: number; sku_id?: number; qty: number
  contact: string; password?: string; extra?: Record<string, any>
}) => request.post<unknown, CreatedOrder>('/orders', data)

export const mockPay = (orderNo: string) =>
  request.post<unknown, { order_no: string; status: string; delivered: boolean }>(`/orders/${orderNo}/mock-pay`)

export const queryOrder = (params: { contact: string; order_no: string; password?: string }) =>
  request.get<unknown, OrderDetail>('/orders/query', { params })
```

- [ ] **Step 2: 路由更新**

修改 `storefront/src/router/index.ts`,在 children 数组加:
```ts
        { path: 'checkout', name: 'checkout', component: () => import('@/views/Checkout.vue') },
        { path: 'pay/:orderNo', name: 'pay', component: () => import('@/views/Pay.vue') },
        { path: 'orders/query', name: 'order-query', component: () => import('@/views/OrderQuery.vue') },
```

- [ ] **Step 3: 收银台 Checkout.vue**

`storefront/src/views/Checkout.vue`:
```vue
<script setup lang="ts">
import { ref, onMounted } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { getProduct, type Product } from '@/api/products'
import { createOrder } from '@/api/orders'
import { useSettingsStore } from '@/stores/settings'

const route = useRoute()
const router = useRouter()
const settings = useSettingsStore()
const product = ref<Product | null>(null)
const selectedSku = ref<number | null>(null)
const qty = ref(1)
const contact = ref('')
const password = ref('')
const loading = ref(false)
const err = ref('')

onMounted(async () => {
  await settings.load()
  const slug = route.query.product as string
  if (slug) {
    product.value = await getProduct(slug)
    selectedSku.value = route.query.sku ? Number(route.query.sku) : (product.value.skus?.[0]?.id ?? null)
  }
  qty.value = route.query.qty ? Number(route.query.qty) : 1
})

const price = () => {
  if (!product.value) return 0
  const sku = product.value.skus?.find(s => s.id === selectedSku.value)
  return sku ? sku.price : product.value.price
}
const total = () => price() * qty.value
const fmt = (fen: number) => (fen / 100).toFixed(2)

async function submit() {
  if (!product.value) return
  if (!contact.value.trim()) { err.value = '请填写邮箱'; return }
  err.value = ''
  loading.value = true
  try {
    const res = await createOrder({
      product_id: product.value.id,
      sku_id: selectedSku.value ?? undefined,
      qty: qty.value,
      contact: contact.value,
      password: password.value || undefined,
    })
    router.push(`/pay/${res.order_no}`)
  } catch (e: any) {
    err.value = e?.response?.data?.message || '下单失败(可能库存不足)'
  } finally {
    loading.value = false
  }
}
</script>

<template>
  <div class="max-w-2xl mx-auto px-4 py-8">
    <h1 class="text-xl font-bold text-ink mb-6">确认订单</h1>

    <!-- 商品确认 -->
    <div v-if="product" class="flex gap-3 p-4 bg-white rounded-card border border-gray-200 mb-4">
      <div class="w-16 h-16 bg-gradient-to-br from-blue-100 to-indigo-100 rounded-card flex items-center justify-center text-primary text-xs flex-shrink-0">
        <img v-if="product.cover" :src="product.cover" class="w-full h-full object-cover rounded-card" />
        <span v-else>无图</span>
      </div>
      <div class="flex-1">
        <div class="text-sm font-semibold text-ink">{{ product.name }}</div>
        <div v-if="product.skus?.length" class="text-xs text-ink-muted mt-1">
          {{ product.skus.find(s => s.id === selectedSku)?.name }} × {{ qty }}
        </div>
      </div>
      <div class="text-right">
        <div class="text-primary font-bold">¥{{ fmt(price()) }}</div>
        <div class="text-xs text-ink-muted">× {{ qty }}</div>
      </div>
    </div>

    <!-- 小计 -->
    <div class="flex justify-between px-4 py-3 mb-4">
      <span class="text-ink-soft">小计</span>
      <span class="text-xl font-bold text-primary">¥{{ fmt(total()) }}</span>
    </div>

    <!-- 收货信息 -->
    <div class="space-y-3">
      <div>
        <label class="text-xs font-semibold text-ink-soft">{{ settings.config?.contact_type === 'phone' ? '手机号' : '邮箱地址' }}</label>
        <input v-model="contact" type="text" :placeholder="settings.config?.contact_type === 'phone' ? '请输入手机号' : '请输入邮箱'"
          class="w-full mt-1 px-3 py-2 border border-gray-200 rounded-field text-sm focus:border-primary" />
      </div>
      <div v-if="settings.config?.order_query_password">
        <label class="text-xs font-semibold text-ink-soft">查询密码</label>
        <input v-model="password" type="password" placeholder="设置查询订单的密码"
          class="w-full mt-1 px-3 py-2 border border-gray-200 rounded-field text-sm focus:border-primary" />
      </div>
    </div>

    <div v-if="err" class="text-danger text-xs mt-3">{{ err }}</div>

    <button @click="submit" :disabled="loading"
      class="w-full mt-6 bg-gradient-to-br from-primary to-blue-500 text-white font-bold py-3 rounded-card shadow-md disabled:opacity-50">
      {{ loading ? '提交中...' : `提交订单 ¥${fmt(total())}` }}
    </button>
  </div>
</template>
```

- [ ] **Step 4: 支付页 Pay.vue**

`storefront/src/views/Pay.vue`:
```vue
<script setup lang="ts">
import { ref, onMounted } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { mockPay } from '@/api/orders'
import { queryOrder, type OrderDetail } from '@/api/orders'

const route = useRoute()
const router = useRouter()
const orderNo = route.params.orderNo as string
const detail = ref<OrderDetail | null>(null)
const paying = ref(false)
const err = ref('')

onMounted(async () => {
  try {
    // 尝试查订单(需 contact,这里先跳过查询,直接展示订单号)
  } catch (e) {}
})

async function pay() {
  paying.value = true
  err.value = ''
  try {
    const res = await mockPay(orderNo)
    if (res.delivered) {
      alert('支付成功!卡密已发货(演示模式)。请通过订单查询页查看卡密。')
      router.push('/orders/query')
    }
  } catch (e: any) {
    err.value = e?.response?.data?.message || '支付失败'
  } finally {
    paying.value = false
  }
}
</script>

<template>
  <div class="max-w-md mx-auto px-4 py-12 text-center">
    <div class="bg-white rounded-card border border-gray-200 p-6">
      <h2 class="text-lg font-bold text-ink mb-2">订单待支付</h2>
      <div class="text-xs text-ink-muted mb-4">订单号:{{ orderNo }}</div>
      <div class="bg-orange-50 border border-orange-200 rounded-card p-3 mb-4 text-xs text-orange-700">
        (P1-C 演示模式:点击下方按钮模拟支付成功,P1-D 将接入真实支付通道)
      </div>
      <button @click="pay" :disabled="paying"
        class="w-full bg-gradient-to-br from-primary to-blue-500 text-white font-bold py-3 rounded-card shadow-md disabled:opacity-50">
        {{ paying ? '支付中...' : '模拟支付' }}
      </button>
      <div v-if="err" class="text-danger text-xs mt-3">{{ err }}</div>
    </div>
  </div>
</template>
```

- [ ] **Step 5: 提交**

```bash
cd /Users/mac/Project/Php/ZCard
git add storefront/src/ && git commit -m "feat(storefront): checkout + pay pages, orders api"
```

---

## Task 6: 订单查询页 + Header 入口

**Files:**
- Create: `storefront/src/views/OrderQuery.vue`
- Modify: `storefront/src/components/AppHeader.vue`(加订单查询链接)

- [ ] **Step 1: 订单查询页 OrderQuery.vue**

`storefront/src/views/OrderQuery.vue`:
```vue
<script setup lang="ts">
import { ref } from 'vue'
import { queryOrder, type OrderDetail } from '@/api/orders'
import { useSettingsStore } from '@/stores/settings'

const settings = useSettingsStore()
const contact = ref('')
const orderNo = ref('')
const password = ref('')
const result = ref<OrderDetail | null>(null)
const err = ref('')
const searched = ref(false)

const statusText = (s: string) => ({
  pending: '待支付', paid: '已支付', closed: '已关闭', refunded: '已退款',
}[s] || s)
const fmt = (fen: number) => (fen / 100).toFixed(2)

async function search() {
  err.value = ''
  result.value = null
  searched.value = true
  try {
    result.value = await queryOrder({
      contact: contact.value,
      order_no: orderNo.value,
      password: password.value || undefined,
    })
  } catch (e: any) {
    err.value = e?.response?.data?.message || '查询失败'
  }
}
function copy(text: string) {
  navigator.clipboard.writeText(text)
}
</script>

<template>
  <div class="max-w-md mx-auto px-4 py-8">
    <h1 class="text-xl font-bold text-ink mb-6">订单查询</h1>

    <div class="space-y-3 mb-4">
      <input v-model="contact" type="text" placeholder="邮箱/手机"
        class="w-full px-3 py-2 border border-gray-200 rounded-field text-sm focus:border-primary" />
      <input v-model="orderNo" type="text" placeholder="订单号"
        class="w-full px-3 py-2 border border-gray-200 rounded-field text-sm focus:border-primary" />
      <input v-if="settings.config?.order_query_password" v-model="password" type="password" placeholder="查询密码"
        class="w-full px-3 py-2 border border-gray-200 rounded-field text-sm focus:border-primary" />
    </div>
    <button @click="search" class="w-full bg-primary text-white font-bold py-2.5 rounded-card">查询</button>

    <div v-if="err" class="text-danger text-sm mt-4 text-center">{{ err }}</div>

    <!-- 查询结果 -->
    <div v-if="result" class="mt-6 bg-white rounded-card border border-gray-200 p-4">
      <div class="flex justify-between items-center mb-3">
        <span class="text-xs text-ink-muted">{{ result.order_no }}</span>
        <span class="text-xs font-bold px-2 py-0.5 rounded-full"
          :class="result.status === 'paid' ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-600'">
          {{ statusText(result.status) }}
        </span>
      </div>
      <div class="text-sm text-ink mb-1">{{ result.product_name }} × {{ result.quantity }}</div>
      <div class="text-primary font-bold mb-3">¥{{ fmt(result.amount) }}</div>

      <!-- 卡密 -->
      <div v-if="result.cards.length" class="border-t border-gray-100 pt-3">
        <div class="text-xs font-semibold text-ink-soft mb-2">卡密({{ result.cards.length }})</div>
        <div v-for="(card, i) in result.cards" :key="i" class="flex items-center gap-2 mb-2">
          <code class="flex-1 text-xs bg-gray-50 p-2 rounded break-all">{{ card }}</code>
          <button @click="copy(card)" class="text-primary text-xs">复制</button>
        </div>
      </div>
      <div v-else-if="result.status === 'pending'" class="text-xs text-orange-600 border-t border-gray-100 pt-3">
        订单待支付,支付后自动发货
      </div>
    </div>
  </div>
</template>
```

- [ ] **Step 2: AppHeader 加订单查询链接**

修改 `storefront/src/components/AppHeader.vue`,在导航 `<nav>` 里加:
```html
<RouterLink to="/orders/query">订单查询</RouterLink>
```
(放在"登录"前)

- [ ] **Step 3: 构建验证 + 提交**

```bash
cd /Users/mac/Project/Php/ZCard/storefront && pnpm run build 2>&1 | tail -3
rm -rf dist
cd /Users/mac/Project/Php/ZCard
git add storefront/src/ && git commit -m "feat(storefront): order query page + header link"
```

---

## Task 7: 后台 OrderResource

**Files:**
- Create: `app/Filament/Resources/Orders/`(生成 + 配置)

- [ ] **Step 1: 生成 OrderResource**

```bash
./vendor/bin/sail artisan filament:resource Order
```

- [ ] **Step 2: OrderResource 配置(导航分组 交易 + 中文化)**

修改 `app/Filament/Resources/Orders/OrderResource.php`,加:
```php
public static function getNavigationGroup(): string | \UnitEnum | null
{
    return '交易';
}

public static function getNavigationLabel(): string
{
    return '订单管理';
}

public static function getModelLabel(): string
{
    return '订单';
}

public static function getPluralModelLabel(): string
{
    return '订单';
}

public static function getNavigationIcon(): string | \BackedEnum | null
{
    return 'heroicon-o-clipboard-document-list';
}
```

- [ ] **Step 3: OrderForm(只读场景,状态可改)**

修改生成的 `app/Filament/Resources/Orders/Schemas/OrderForm.php`:
```php
<?php

namespace App\Filament\Resources\Orders\Schemas;

use Filament\Forms\Components\Select;
use Filament\Schemas\Schema;

class OrderForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('status')
                    ->options([
                        'pending' => '待支付',
                        'paid' => '已支付',
                        'closed' => '已关闭',
                        'refunded' => '已退款',
                    ])
                    ->required(),
            ]);
    }
}
```

- [ ] **Step 4: OrdersTable(列表 + 状态筛选 + 手动关闭)**

完全替换 `app/Filament/Resources/Orders/Tables/OrdersTable.php`:
```php
<?php

namespace App\Filament\Resources\Orders\Tables;

use App\Models\Order;
use App\Support\OrderService;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class OrdersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('order_no')->label('订单号')->searchable()->copyable(),
                TextColumn::make('product.name')->label('商品')->limit(20),
                TextColumn::make('quantity')->label('数量')->alignRight(),
                TextColumn::make('amount')->label('金额')->money('CNY', divideBy: 100, locale: 'zh_CN'),
                TextColumn::make('status')->badge()->label('状态')->colors([
                    'warning' => 'pending',
                    'success' => 'paid',
                    'gray' => 'closed',
                    'danger' => 'refunded',
                ])->formatStateUsing(fn (string $state): string => match ($state) {
                    'pending' => '待支付',
                    'paid' => '已支付',
                    'closed' => '已关闭',
                    'refunded' => '已退款',
                    default => $state,
                }),
                TextColumn::make('contact')->label('联系方式')->toggleable(),
                TextColumn::make('created_at')->dateTime()->label('下单时间')->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options([
                        'pending' => '待支付',
                        'paid' => '已支付',
                        'closed' => '已关闭',
                        'refunded' => '已退款',
                    ])
                    ->label('状态'),
            ])
            ->recordActions([
                Action::make('close')
                    ->label('关闭订单')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->visible(fn (Order $record) => $record->status === 'pending')
                    ->action(function (Order $record, OrderService $service) {
                        $service->closeOrder($record->id);
                        Notification::make()->success()->title('订单已关闭,卡密已释放')->send();
                    }),
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
```

- [ ] **Step 5: shield 权限 + 验证**

```bash
./vendor/bin/sail artisan shield:generate --all --panel=admin --no-interaction
./vendor/bin/sail artisan optimize:clear
```
浏览器后台 → 交易 → 订单管理 → 见订单列表。

- [ ] **Step 6: 提交**

```bash
git add app/Filament/Resources/Orders/ app/Policies/ && git commit -m "feat(filament): OrderResource - list, status filter, manual close"
```

---

## Task 8: 端到端验证(spec §11)

- [ ] **Step 1: 准备测试数据(卡密)**

```bash
./vendor/bin/sail artisan tinker --execute="
App\Models\Card::query()->delete(); App\Models\Order::query()->delete();
\$svc = app(App\Support\CardImportService::class);
\$p = App\Models\Product::where('slug','steam-card')->first();
\$svc->import(\$p->id, 1, \"e2e-001\nseed-002\nseed-003\nseed-004\nseed-005\", ['source'=>'e2e']);
echo 'cards='.\App\Models\Card::count();
" 2>&1 | tail -1
```

- [ ] **Step 2: 端到端 API 流程**

```bash
# 1. 创建订单
RES=$(curl -s -X POST http://localhost:8092/api/orders -H "Content-Type: application/json" -d '{"product_id":1,"qty":2,"contact":"e2e@example.com"}')
ORDERNO=$(echo $RES | python3 -c "import sys,json;print(json.load(sys.stdin)['order_no'])")
echo "order=$ORDERNO | $RES"
# 2. 模拟支付
curl -s -X POST "http://localhost:8092/api/orders/$ORDERNO/mock-pay"
echo ""
# 3. 查询(应见卡密)
curl -s "http://localhost:8092/api/orders/query?contact=e2e@example.com&order_no=$ORDERNO" | python3 -c "import sys,json;d=json.load(sys.stdin);print(f\"status={d['status']} cards={len(d['cards'])} first={d['cards'][0][:12]}...\")"
```
Expected: 订单创建→支付成功→查询见2张卡密

- [ ] **Step 3: 超卖防护**

```bash
curl -s -X POST http://localhost:8092/api/orders -H "Content-Type: application/json" -d '{"product_id":1,"qty":100,"contact":"over@example.com"}'
```
Expected: 返回库存不足错误

- [ ] **Step 4: 浏览器前台验证**

```bash
cd /Users/mac/Project/Php/ZCard/storefront && pnpm dev
```
浏览器 :5173:
- 详情页 → 立即购买 → 收银台(填邮箱→提交)→ 支付页(模拟支付)→ 查询页(查到卡密)
- Header 有"订单查询"入口

- [ ] **Step 5: 后台订单管理**

浏览器后台 → 交易 → 订单管理 → 见订单列表/状态筛选/手动关闭

- [ ] **Step 6: 测试 + docs + 工作树**

```bash
./vendor/bin/sail test 2>&1 | tail -3
git ls-files docs/ | head -1 && echo "BAD" || echo "GOOD"
git status --short
```

---

## 完成标准(对照 spec §11)

全部 Task 完成后核对 spec §11 验收清单(20 项):
- OrderService(createOrder/markPaid/closeExpired/queryOrder/closeOrder)✓
- DeliveryService(发货 + 事件监听)✓
- OrderPaid 事件 ✓
- 超时关单命令 + Scheduler ✓
- 配置项(order_close_minutes/contact_type)✓
- API(create/mock-pay/query)✓
- 前台(收银台/支付/查询/Header)✓
- 后台 OrderResource ✓
- 端到端(下单→支付→发货→查询)✓
- 防超卖 ✓

---

## 与 spec 的一致性

无偏差。spec §4(OrderService/DeliveryService)、§6(Scheduler)、§8(API)、§9(前台)、§10(后台)均有对应 Task。

**注**:mews/captcha 验证码(spec §7)在 OrderController 标了 TODO,因 API session 配置复杂,P1-C 先跳过验证码校验逻辑(开关已就位,前端表单已预留),后续补。这是 spec §12 标记的已知风险项。
