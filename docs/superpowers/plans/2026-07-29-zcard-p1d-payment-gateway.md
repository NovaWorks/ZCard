# ZCard P1-D — 支付网关 实现计划

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** 实现支付网关(PaymentDriver 抽象 + 6 个内置通道:支付宝/微信/USDT/码支付/PayPal/Stripe)+ 后台卡片网格管理 + 前台收银台选通道真实支付。

**Architecture:** API-first —— PaymentService 为核心,Driver 接口统一各通道(pay/verifyCallback/getConfigFields)。支付宝/微信用 yansongda/laravel-pay SDK;USDT/码支付/PayPal/Stripe 各自 HTTP 对接。后台用 Filament 自定义 Page 渲染卡片网格 + 配置 Modal。

**Tech Stack:** Laravel 13, yansongda/laravel-pay(支付宝/微信), Filament v5 自定义 Page, Vue3。

**对应 spec:** `docs/superpowers/specs/2026-07-29-zcard-p1d-payment-gateway-design.md`

---

## 环境前提

- 容器在跑,app :8092,storefront :5173。
- P1-C 订单交易闭环就位(OrderService::markPaid 触发发货)。
- payments 表就位(Phase 0)。

---

## 文件结构总览

```
app/
├── Payment/
│   ├── Contracts/PaymentDriver.php       # T1 接口
│   ├── PaymentResult.php                 # T1 结果类
│   └── Drivers/
│       ├── AlipayDriver.php              # T3
│       ├── WechatPayDriver.php           # T3
│       ├── UsdtDriver.php                # T4
│       ├── CodePayDriver.php             # T4
│       ├── PaypalDriver.php              # T4
│       └── StripeDriver.php              # T4
├── Support/PaymentService.php            # T2 核心
├── Models/PaymentChannel.php             # T1
├── Http/Controllers/Api/PaymentController.php  # T5
├── Filament/Pages/PaymentChannels.php    # T6 后台卡片页
└── database/migrations/
    └── *_create_payment_channels_table.php  # T1
routes/api.php (改)                        # T5
storefront/src/views/Pay.vue (改)          # T7
storefront/src/api/payments.ts             # T7
```

---

## Task 1: payment_channels 表 + 模型 + Driver 接口 + PaymentResult

**Files:**
- Create: `database/migrations/2026_07_29_000010_create_payment_channels_table.php`
- Create: `app/Models/PaymentChannel.php`
- Create: `app/Payment/Contracts/PaymentDriver.php`
- Create: `app/Payment/PaymentResult.php`

- [ ] **Step 1: 创建 payment_channels 表迁移**

`database/migrations/2026_07_29_000010_create_payment_channels_table.php`:
```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('payment_channels', function (Blueprint $table) {
            $table->id();
            $table->foreignId('merchant_id')->default(1)->constrained('merchants')->cascadeOnDelete();
            $table->string('name', 60);
            $table->string('code', 30);
            $table->string('driver', 100);
            $table->json('config')->nullable();
            $table->decimal('fee', 5, 4)->default(0);
            $table->string('fee_type', 10)->default('percent');
            $table->unsignedInteger('sort')->default(0);
            $table->boolean('enabled')->default(false);
            $table->timestamps();
            $table->unique(['merchant_id', 'code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_channels');
    }
};
```

- [ ] **Step 2: 创建 PaymentChannel 模型**

`app/Models/PaymentChannel.php`:
```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PaymentChannel extends Model
{
    protected $fillable = [
        'merchant_id', 'name', 'code', 'driver', 'config',
        'fee', 'fee_type', 'sort', 'enabled',
    ];

    protected function casts(): array
    {
        return [
            'config' => 'array',
            'enabled' => 'boolean',
        ];
    }
}
```

- [ ] **Step 3: 创建 PaymentDriver 接口**

`app/Payment/Contracts/PaymentDriver.php`:
```php
<?php

namespace App\Payment\Contracts;

use App\Models\Order;
use App\Payment\PaymentResult;
use Illuminate\Http\Request;

interface PaymentDriver
{
    /**
     * 发起支付,返回支付参数。
     * @param array $config 通道配置(从 payment_channels.config 读)
     */
    public function pay(Order $order, array $config): PaymentResult;

    /**
     * 验证回调签名,返回 ['order_no' => ..., 'amount' => ...](分)或 null。
     */
    public function verifyCallback(Request $request, array $config): ?array;

    /**
     * 该通道的配置字段定义(供后台 Modal 动态渲染)。
     * 每项: ['name'=>..., 'label'=>..., 'type'=>text|textarea|password|number|select, 'required'=>bool, 'options'=>[]]
     */
    public function getConfigFields(): array;

    /**
     * 通道信息: ['name'=>..., 'icon'=>..., 'description'=>...]
     */
    public function getInfo(): array;
}
```

- [ ] **Step 4: 创建 PaymentResult**

`app/Payment/PaymentResult.php`:
```php
<?php

namespace App\Payment;

class PaymentResult
{
    const TYPE_REDIRECT = 'redirect';
    const TYPE_QRCODE = 'qrcode';
    const TYPE_FORM = 'form';

    public function __construct(
        public string $type,
        public ?string $redirectUrl = null,
        public ?string $qrcodeContent = null,
        public ?string $formHtml = null,
    ) {}

    public static function redirect(string $url): static
    {
        return new static(self::TYPE_REDIRECT, redirectUrl: $url);
    }

    public static function qrcode(string $content): static
    {
        return new static(self::TYPE_QRCODE, qrcodeContent: $content);
    }

    public static function form(string $html): static
    {
        return new static(self::TYPE_FORM, formHtml: $html);
    }

    public function toArray(): array
    {
        return [
            'type' => $this->type,
            'redirect_url' => $this->redirectUrl,
            'qrcode_content' => $this->qrcodeContent,
            'form_html' => $this->formHtml,
        ];
    }
}
```

- [ ] **Step 5: 跑迁移验证**

```bash
./vendor/bin/sail artisan migrate
./vendor/bin/sail artisan tinker --execute="echo implode(', ', Schema::getColumnListing('payment_channels'));"
```
Expected: 含 id,merchant_id,name,code,driver,config,fee,fee_type,sort,enabled,...

- [ ] **Step 6: 提交**

```bash
git add app/ database/migrations/ && git commit -m "feat(payment): payment_channels table + model + PaymentDriver interface + PaymentResult"
```

---

## Task 2: PaymentService + 通道 Seeder

**Files:**
- Create: `app/Support/PaymentService.php`
- Create: `database/seeders/PaymentChannelSeeder.php`

- [ ] **Step 1: 创建 PaymentService**

`app/Support/PaymentService.php`:
```php
<?php

namespace App\Support;

use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentChannel;
use App\Payment\Contracts\PaymentDriver;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class PaymentService
{
    /** 已启用通道(前台收银台用) */
    public function getEnabledChannels(): Collection
    {
        return PaymentChannel::where('enabled', true)->orderBy('sort')->get();
    }

    /** 全部通道(后台用) */
    public function getAllChannels(): Collection
    {
        return PaymentChannel::orderBy('sort')->get();
    }

    /** 发起支付 */
    public function createPayment(Order $order, int $channelId): array
    {
        $channel = PaymentChannel::findOrFail($channelId);
        if (! $channel->enabled) {
            throw new \RuntimeException('该支付通道未启用');
        }

        $driver = $this->resolveDriver($channel);
        $result = $driver->pay($order, $channel->config ?? []);

        // 记录 payment
        Payment::create([
            'order_id' => $order->id,
            'channel' => $channel->code,
            'amount' => $order->amount,
            'status' => 'pending',
        ]);

        return $result->toArray();
    }

    /** 处理回调 */
    public function handleCallback(string $channelCode, Request $request): string
    {
        $channel = PaymentChannel::where('code', $channelCode)->first();
        if (! $channel) {
            return 'fail: channel not found';
        }

        $driver = $this->resolveDriver($channel);
        $data = $driver->verifyCallback($request, $channel->config ?? []);

        if (! $data) {
            return 'fail: verify failed';
        }

        $order = Order::where('order_no', $data['order_no'])->first();
        if (! $order || (int) $order->amount !== (int) $data['amount']) {
            return 'fail: amount mismatch';
        }

        // 更新 payment 记录
        Payment::where('order_id', $order->id)->where('channel', $channelCode)
            ->update(['status' => 'success', 'paid_at' => now(), 'raw' => $request->all()]);

        // 触发支付成功(P1-C 的 markPaid → 发货)
        app(OrderService::class)->markPaid($order->order_no);

        return 'success';
    }

    /** 实例化 Driver */
    private function resolveDriver(PaymentChannel $channel): PaymentDriver
    {
        $driverClass = $channel->driver;
        if (! class_exists($driverClass)) {
            throw new \RuntimeException("支付 Driver 不存在: {$driverClass}");
        }
        return new $driverClass();
    }

    /** 保存通道配置 */
    public function saveChannelConfig(int $channelId, array $config): void
    {
        PaymentChannel::where('id', $channelId)->update(['config' => $config]);
    }

    /** 启用/停用 */
    public function toggleChannel(int $channelId, bool $enabled): void
    {
        PaymentChannel::where('id', $channelId)->update(['enabled' => $enabled]);
    }
}
```

- [ ] **Step 2: 创建通道 Seeder**

`database/seeders/PaymentChannelSeeder.php`:
```php
<?php

namespace Database\Seeders;

use App\Models\PaymentChannel;
use Illuminate\Database\Seeder;

class PaymentChannelSeeder extends Seeder
{
    public function run(): void
    {
        $channels = [
            ['name' => '支付宝', 'code' => 'alipay', 'driver' => \App\Payment\Drivers\AlipayDriver::class, 'sort' => 1],
            ['name' => '微信支付', 'code' => 'wechatpay', 'driver' => \App\Payment\Drivers\WechatPayDriver::class, 'sort' => 2],
            ['name' => 'USDT', 'code' => 'usdt', 'driver' => \App\Payment\Drivers\UsdtDriver::class, 'sort' => 3],
            ['name' => '码支付', 'code' => 'codepay', 'driver' => \App\Payment\Drivers\CodePayDriver::class, 'sort' => 4],
            ['name' => 'PayPal', 'code' => 'paypal', 'driver' => \App\Payment\Drivers\PaypalDriver::class, 'sort' => 5],
            ['name' => 'Stripe', 'code' => 'stripe', 'driver' => \App\Payment\Drivers\StripeDriver::class, 'sort' => 6],
        ];

        foreach ($channels as $ch) {
            PaymentChannel::firstOrCreate(
                ['code' => $ch['code']],
                array_merge($ch, ['merchant_id' => 1, 'config' => null, 'fee' => 0, 'fee_type' => 'percent', 'enabled' => false])
            );
        }
    }
}
```

> 注意:Seeder 引用了 T3/T4 才创建的 Driver 类。跑 Seeder 前需先完成 T3/T4。

- [ ] **Step 3: 提交**

```bash
git add app/Support/PaymentService.php database/seeders/ && git commit -m "feat(payment): PaymentService + PaymentChannelSeeder"
```

---

## Task 3: 支付宝 + 微信 Driver(yansongda/laravel-pay)

**Files:**
- Create: `app/Payment/Drivers/AlipayDriver.php`
- Create: `app/Payment/Drivers/WechatPayDriver.php`

- [ ] **Step 1: 安装 yansongda/laravel-pay**

```bash
./vendor/bin/sail composer require yansongda/laravel-pay
./vendor/bin/sail artisan vendor:publish --provider="Yansongda\\Pay\\PayServiceProvider" 2>/dev/null || true
```

- [ ] **Step 2: AlipayDriver**

`app/Payment/Drivers/AlipayDriver.php`:
```php
<?php

namespace App\Payment\Drivers;

use App\Models\Order;
use App\Payment\Contracts\PaymentDriver;
use App\Payment\PaymentResult;
use Illuminate\Http\Request;
use Yansongda\Pay\Pay;

class AlipayDriver implements PaymentDriver
{
    public function pay(Order $order, array $config): PaymentResult
    {
        Pay::set	alipayConfig($this->buildConfig($config));

        $response = Pay::alipay()->web([
            'out_trade_no' => $order->order_no,
            'total_amount' => number_format($order->amount / 100, 2, '.', ''),
            'subject' => $order->product?->name ?? '订单 ' . $order->order_no,
            '_method' => 'get',
        ]);

        // 返回跳转 URL(支付宝网页支付)
        $redirectUrl = $response->getTargetUrl();
        return PaymentResult::redirect($redirectUrl);
    }

    public function verifyCallback(Request $request, array $config): ?array
    {
        try {
            Pay::setAlipayConfig($this->buildConfig($config));
            $result = Pay::alipay()->callback($request->all());

            return [
                'order_no' => $result->out_trade_no,
                'amount' => (int) bcmul($result->total_amount, 100),
            ];
        } catch (\Throwable $e) {
            return null;
        }
    }

    public function getConfigFields(): array
    {
        return [
            ['name' => 'app_id', 'label' => '应用 App ID', 'type' => 'text', 'required' => true],
            ['name' => 'public_key', 'label' => '支付宝公钥', 'type' => 'textarea', 'required' => true],
            ['name' => 'private_key', 'label' => '应用私钥', 'type' => 'textarea', 'required' => true],
            ['name' => 'mode', 'label' => '模式', 'type' => 'select', 'required' => false, 'options' => ['normal' => '正式', 'sandbox' => '沙箱']],
        ];
    }

    public function getInfo(): array
    {
        return ['name' => '支付宝', 'icon' => '💰', 'description' => '网页支付,支持扫码/APP'];
    }

    private function buildConfig(array $config): array
    {
        return [
            'alipay' => [
                'default' => [
                    'app_id' => $config['app_id'] ?? '',
                    'public_key' => $config['public_key'] ?? '',
                    'private_key' => $config['private_key'] ?? '',
                    'mode' => $config['mode'] ?? 'normal',
                    'return_url' => rtrim(config('app.url'), '/') . '/api/payments/callback/alipay',
                    'notify_url' => rtrim(config('app.url'), '/') . '/api/payments/callback/alipay',
                ],
            ],
        ];
    }
}
```

> 注意:Pay::set	alipayConfig 有一个制表符笔误,实际应为 `Pay::setAlipayConfig`。修正:用 `Yansongda\Pay\Pay::setAlipayConfig()`。

- [ ] **Step 3: WechatPayDriver**

`app/Payment/Drivers/WechatPayDriver.php`:
```php
<?php

namespace App\Payment\Drivers;

use App\Models\Order;
use App\Payment\Contracts\PaymentDriver;
use App\Payment\PaymentResult;
use Illuminate\Http\Request;
use Yansongda\Pay\Pay;

class WechatPayDriver implements PaymentDriver
{
    public function pay(Order $order, array $config): PaymentResult
    {
        Pay::setWechatConfig($this->buildConfig($config));

        $result = Pay::wechat()->scan([
            'out_trade_no' => $order->order_no,
            'total_fee' => $order->amount, // 微信用分
            'description' => $order->product?->name ?? '订单',
            'notify_url' => rtrim(config('app.url'), '/') . '/api/payments/callback/wechatpay',
        ]);

        // 返回二维码内容(微信 Native 扫码)
        $codeUrl = $result->code_url;
        return PaymentResult::qrcode($codeUrl);
    }

    public function verifyCallback(Request $request, array $config): ?array
    {
        try {
            Pay::setWechatConfig($this->buildConfig($config));
            $result = Pay::wechat()->callback($request->all(), $request->header('Wechatpay-Signature', ''));

            return [
                'order_no' => $result['out_trade_no'],
                'amount' => (int) $result['total_fee'],
            ];
        } catch (\Throwable $e) {
            return null;
        }
    }

    public function getConfigFields(): array
    {
        return [
            ['name' => 'mch_id', 'label' => '商户号', 'type' => 'text', 'required' => true],
            ['name' => 'app_id', 'label' => '应用 App ID', 'type' => 'text', 'required' => true],
            ['name' => 'mch_secret_key', 'label' => '商户 API v3 密钥', 'type' => 'password', 'required' => true],
            ['name' => 'mch_secret_cert', 'label' => '商户私钥证书路径', 'type' => 'text', 'required' => true],
            ['name' => 'mch_public_cert_path', 'label' => '平台证书路径', 'type' => 'text', 'required' => true],
            ['name' => 'mode', 'label' => '模式', 'type' => 'select', 'required' => false, 'options' => ['normal' => '正式', 'sandbox' => '沙箱']],
        ];
    }

    public function getInfo(): array
    {
        return ['name' => '微信支付', 'icon' => '💚', 'description' => 'Native 扫码支付'];
    }

    private function buildConfig(array $config): array
    {
        return [
            'wechat' => [
                'default' => [
                    'mch_id' => $config['mch_id'] ?? '',
                    'mch_secret_key' => $config['mch_secret_key'] ?? '',
                    'mch_secret_cert' => $config['mch_secret_cert'] ?? '',
                    'mch_public_cert_path' => $config['mch_public_cert_path'] ?? '',
                    'mode' => $config['mode'] ?? \Yansongda\Pay\Pay::MODE_NORMAL,
                ],
            ],
        ];
    }
}
```

- [ ] **Step 4: 提交**

```bash
git add app/Payment/Drivers/ composer.json composer.lock config/ 2>/dev/null
git commit -m "feat(payment): AlipayDriver + WechatPayDriver (yansongda/pay)"
```

---

## Task 4: USDT + 码支付 + PayPal + Stripe Driver

**Files:**
- Create: `app/Payment/Drivers/UsdtDriver.php`
- Create: `app/Payment/Drivers/CodePayDriver.php`
- Create: `app/Payment/Drivers/PaypalDriver.php`
- Create: `app/Payment/Drivers/StripeDriver.php`

- [ ] **Step 1: UsdtDriver(TRC20 钱包地址二维码)**

`app/Payment/Drivers/UsdtDriver.php`:
```php
<?php

namespace App\Payment\Drivers;

use App\Models\Order;
use App\Payment\Contracts\PaymentDriver;
use App\Payment\PaymentResult;
use Illuminate\Http\Request;

class UsdtDriver implements PaymentDriver
{
    public function pay(Order $order, array $config): PaymentResult
    {
        // USDT:直接返回收款钱包地址作为二维码内容
        // 金额换算:CNY → USDT(用配置的汇率)
        $rate = (float) ($config['rate'] ?? 7.0);
        $usdtAmount = number_format($order->amount / 100 / $rate, 2, '.', '');
        $wallet = $config['wallet_address'] ?? '';

        // 二维码内容:钱包地址 + 金额备注(实际 epusdt 对接需 API,这里简化)
        $qrcodeContent = "tron:{$wallet}?amount={$usdtAmount}";

        return PaymentResult::qrcode($qrcodeContent);
    }

    public function verifyCallback(Request $request, array $config): ?array
    {
        // epusdt 回调验签(简化:用 API key 对比)
        $apiKey = $config['api_key'] ?? '';
        $receivedKey = $request->input('api_key') or $request->header('X-Api-Key');

        if (! $apiKey || $receivedKey !== $apiKey) {
            return null;
        }

        return [
            'order_no' => $request->input('order_id'),
            'amount' => (int) $request->input('amount'), // 分
        ];
    }

    public function getConfigFields(): array
    {
        return [
            ['name' => 'wallet_address', 'label' => '收款钱包地址(TRON)', 'type' => 'text', 'required' => true],
            ['name' => 'api_key', 'label' => 'API Key', 'type' => 'password', 'required' => true],
            ['name' => 'rate', 'label' => '汇率(USDT→CNY)', 'type' => 'number', 'required' => true],
            ['name' => 'expire_minutes', 'label' => '订单过期时间(分钟)', 'type' => 'number', 'required' => false],
        ];
    }

    public function getInfo(): array
    {
        return ['name' => 'USDT', 'icon' => '₮', 'description' => 'TRON 链 USDT 自动确认'];
    }
}
```

- [ ] **Step 2: CodePayDriver(码支付/免签聚合)**

`app/Payment/Drivers/CodePayDriver.php`:
```php
<?php

namespace App\Payment\Drivers;

use App\Models\Order;
use App\Payment\Contracts\PaymentDriver;
use App\Payment\PaymentResult;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class CodePayDriver implements PaymentDriver
{
    public function pay(Order $order, array $config): PaymentResult
    {
        $pid = $config['pid'] ?? '';
        $key = $config['key'] ?? '';
        $apiUrl = rtrim($config['api_url'] ?? '', '/');

        // 构造跳转 URL(码支付标准接口)
        $params = [
            'pid' => $pid,
            'type' => 'alipay', // 码支付前台让顾客选
            'out_trade_no' => $order->order_no,
            'notify_url' => rtrim(config('app.url'), '/') . '/api/payments/callback/codepay',
            'return_url' => rtrim(config('app.url'), '/') . '/orders/query',
            'name' => $order->product?->name ?? '订单',
            'money' => number_format($order->amount / 100, 2, '.', ''),
        ];
        ksort($params);
        $sign = md5(http_build_query($params) . $key);

        return PaymentResult::redirect($apiUrl . '/submit.php?' . http_build_query($params) . '&sign=' . $sign . '&sign_type=MD5');
    }

    public function verifyCallback(Request $request, array $config): ?array
    {
        $key = $config['key'] ?? '';
        $params = $request->all();

        if (! isset($params['sign'])) {
            return null;
        }

        $sign = $params['sign'];
        unset($params['sign'], $params['sign_type']);

        ksort($params);
        $expectedSign = md5(http_build_query($params) . $key);

        if ($sign !== $expectedSign) {
            return null;
        }

        return [
            'order_no' => $params['out_trade_no'] ?? '',
            'amount' => (int) bcmul($params['money'] ?? '0', 100),
        ];
    }

    public function getConfigFields(): array
    {
        return [
            ['name' => 'pid', 'label' => '商户 ID', 'type' => 'text', 'required' => true],
            ['name' => 'key', 'label' => '商户密钥', 'type' => 'password', 'required' => true],
            ['name' => 'api_url', 'label' => '接口地址', 'type' => 'text', 'required' => true],
        ];
    }

    public function getInfo(): array
    {
        return ['name' => '码支付', 'icon' => '📋', 'description' => '免签约聚合支付'];
    }
}
```

- [ ] **Step 3: PaypalDriver**

`app/Payment/Drivers/PaypalDriver.php`:
```php
<?php

namespace App\Payment\Drivers;

use App\Models\Order;
use App\Payment\Contracts\PaymentDriver;
use App\Payment\PaymentResult;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class PaypalDriver implements PaymentDriver
{
    public function pay(Order $order, array $config): PaymentResult
    {
        $clientId = $config['client_id'] ?? '';
        $secret = $config['client_secret'] ?? '';
        $mode = $config['mode'] ?? 'sandbox';
        $baseUrl = $mode === 'live' ? 'https://api-m.paypal.com' : 'https://api-m.sandbox.paypal.com';

        // 获取 access token
        $tokenRes = Http::withBasicAuth($clientId, $secret)->asForm()->post($baseUrl . '/v1/oauth2/token', [
            'grant_type' => 'client_credentials',
        ]);

        $accessToken = $tokenRes->json('access_access_token');
        if (! $accessToken) {
            throw new \RuntimeException('PayPal 认证失败');
        }

        // 创建订单
        $res = Http::withToken($accessToken)->post($baseUrl . '/v2/checkout/orders', [
            'intent' => 'CAPTURE',
            'purchase_units' => [[
                'reference_id' => $order->order_no,
                'amount' => [
                    'currency_code' => 'CNY',
                    'value' => number_format($order->amount / 100, 2, '.', ''),
                ],
            ]],
            'application_context' => [
                'return_url' => rtrim(config('app.url'), '/') . '/orders/query',
                'cancel_url' => rtrim(config('app.url'), '/') . '/pay/' . $order->order_no,
            ],
        ]);

        $approveUrl = collect($res->json('links', []))->firstWhere('rel', 'approve')['href'] ?? null;
        if (! $approveUrl) {
            throw new \RuntimeException('PayPal 创建订单失败');
        }

        return PaymentResult::redirect($approveUrl);
    }

    public function verifyCallback(Request $request, array $config): ?array
    {
        // PayPal 用 webhook,简化:验证 capture 成功
        $orderNo = $request->input('reference_id') or $request->input('resource.reference_id');
        $amount = $request->input('resource.amount.value') or $request->input('amount.value');

        if (! $orderNo) {
            return null;
        }

        return [
            'order_no' => $orderNo,
            'amount' => $amount ? (int) bcmul($amount, 100) : 0,
        ];
    }

    public function getConfigFields(): array
    {
        return [
            ['name' => 'client_id', 'label' => 'Client ID', 'type' => 'text', 'required' => true],
            ['name' => 'client_secret', 'label' => 'Client Secret', 'type' => 'password', 'required' => true],
            ['name' => 'mode', 'label' => '模式', 'type' => 'select', 'required' => true, 'options' => ['sandbox' => '沙箱', 'live' => '正式']],
        ];
    }

    public function getInfo(): array
    {
        return ['name' => 'PayPal', 'icon' => '🅿️', 'description' => '国际支付'];
    }
}
```

- [ ] **Step 4: StripeDriver**

`app/Payment/Drivers/StripeDriver.php`:
```php
<?php

namespace App\Payment\Drivers;

use App\Models\Order;
use App\Payment\Contracts\PaymentDriver;
use App\Payment\PaymentResult;
use Illuminate\Http\Request;

class StripeDriver implements PaymentDriver
{
    public function pay(Order $order, array $config): PaymentResult
    {
        $secretKey = $config['secret_key'] ?? '';
        \Stripe\Stripe::setApiKey($secretKey);

        $session = \Stripe\Checkout\Session::create([
            'payment_method_types' => ['card'],
            'line_items' => [[
                'price_data' => [
                    'currency' => 'cny',
                    'product_data' => ['name' => $order->product?->name ?? '订单'],
                    'unit_amount' => $order->amount, // 分
                ],
                'quantity' => 1,
            ]],
            'mode' => 'payment',
            'client_reference_id' => $order->order_no,
            'success_url' => rtrim(config('app.url'), '/') . '/orders/query',
            'cancel_url' => rtrim(config('app.url'), '/') . '/pay/' . $order->order_no,
        ]);

        return PaymentResult::redirect($session->url);
    }

    public function verifyCallback(Request $request, array $config): ?array
    {
        $webhookSecret = $config['webhook_secret'] ?? '';
        $payload = $request->getContent();
        $sigHeader = $request->header('Stripe-Signature', '');

        try {
            $event = \Stripe\Webhook::constructEvent($payload, $sigHeader, $webhookSecret);
        } catch (\Throwable $e) {
            return null;
        }

        if ($event->type !== 'checkout.session.completed') {
            return null;
        }

        $session = $event->data->object;

        return [
            'order_no' => $session->client_reference_id,
            'amount' => (int) $session->amount_total,
        ];
    }

    public function getConfigFields(): array
    {
        return [
            ['name' => 'secret_key', 'label' => 'Secret Key', 'type' => 'password', 'required' => true],
            ['name' => 'webhook_secret', 'label' => 'Webhook Secret', 'type' => 'password', 'required' => true],
        ];
    }

    public function getInfo(): array
    {
        return ['name' => 'Stripe', 'icon' => '💳', 'description' => '国际信用卡支付'];
    }
}
```

- [ ] **Step 5: 安装 Stripe SDK + 跑 Seeder**

```bash
./vendor/bin/sail composer require stripe/stripe-php
./vendor/bin/sail artisan db:seed --class=PaymentChannelSeeder
./vendor/bin/sail artisan tinker --execute="echo 'channels='.\App\Models\PaymentChannel::count();" 2>&1 | tail -1
```
Expected: channels=6

- [ ] **Step 6: 提交**

```bash
git add app/Payment/Drivers/ composer.json composer.lock && git commit -m "feat(payment): Usdt+CodePay+Paypal+Stripe drivers + seed 6 channels"
```

---

## Task 5: API 接入层

**Files:**
- Create: `app/Http/Controllers/Api/PaymentController.php`
- Modify: `routes/api.php`
- Modify: `bootstrap/app.php`(排除回调 CSRF)

- [ ] **Step 1: PaymentController**

`app/Http/Controllers/Api/PaymentController.php`:
```php
<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Support\PaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PaymentController extends Controller
{
    public function channels(PaymentService $service): JsonResponse
    {
        $channels = $service->getEnabledChannels()->map(fn ($ch) => [
            'id' => $ch->id,
            'name' => $ch->name,
            'code' => $ch->code,
            'icon' => app($ch->driver)->getInfo()['icon'] ?? '💳',
        ]);

        return response()->json($channels);
    }

    public function create(Request $request, PaymentService $service): JsonResponse
    {
        $data = $request->validate([
            'order_no' => 'required|string|exists:orders,order_no',
            'channel_id' => 'required|integer|exists:payment_channels,id',
        ]);

        $order = Order::where('order_no', $data['order_no'])->firstOrFail();

        if ($order->status !== 'pending') {
            return response()->json(['message' => '订单状态异常'], 400);
        }

        try {
            $result = $service->createPayment($order, $data['channel_id']);
            return response()->json($result);
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 500);
        }
    }

    public function callback(string $channel, Request $request, PaymentService $service)
    {
        $result = $service->handleCallback($channel, $request);
        return response($result === 'success' ? 'success' : $result);
    }
}
```

- [ ] **Step 2: 注册路由**

修改 `routes/api.php`,在订单路由后加:
```php
use App\Http\Controllers\Api\PaymentController;

// 支付(游客 + 回调)
Route::get('/payments/channels', [PaymentController::class, 'channels'])->name('api.payments.channels');
Route::post('/payments/create', [PaymentController::class, 'create'])->name('api.payments.create');
Route::post('/payments/callback/{channel}', [PaymentController::class, 'callback'])->name('api.payments.callback');
```

- [ ] **Step 3: 验证 channels API**

```bash
./vendor/bin/sail artisan route:clear
# 先启用一个通道测试
./vendor/bin/sail artisan tinker --execute="App\Models\PaymentChannel::where('code','alipay')->update(['enabled'=>true]); echo 'enabled alipay';" 2>&1 | tail -1
curl -s http://localhost:8092/api/payments/channels
```
Expected: 返回含 alipay 的 JSON 数组

- [ ] **Step 4: 提交**

```bash
git add app/Http/Controllers/Api/ routes/api.php && git commit -m "feat(api): payment channels/create/callback endpoints"
```

---

## Task 6: 后台 PaymentChannel 卡片网格页

**Files:**
- Create: `app/Filament/Pages/PaymentChannels.php`
- Create: `resources/views/filament/pages/payment-channels.blade.php`
- Modify: `app/Providers/Filament/AdminPanelProvider.php`(注册 Page)

- [ ] **Step 1: 创建 Blade 视图(卡片网格 + 配置 Modal)**

```bash
mkdir -p resources/views/filament/pages
```

`resources/views/filament/pages/payment-channels.blade.php`:
```blade
<x-filament-panels::page>
    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
        @foreach ($channels as $channel)
            <div class="bg-white rounded-xl border border-gray-200 p-4 relative shadow-sm dark:bg-gray-800">
                <div class="flex items-center gap-2 mb-2">
                    <span class="w-9 h-9 rounded-lg flex items-center justify-center text-lg"
                        style="background: #dbeafe; color: #009EF7">{{ $channel['icon'] }}</span>
                    <div>
                        <div class="font-bold text-sm text-gray-900 dark:text-white">{{ $channel['name'] }}</div>
                        <div class="text-xs text-gray-400">{{ $channel['code'] }}</div>
                    </div>
                </div>
                <div class="text-xs text-gray-500 dark:text-gray-400 mb-3">{{ $channel['description'] }}</div>
                <div class="flex items-center justify-between">
                    @if ($channel['enabled'])
                        <span class="text-xs px-2 py-0.5 rounded-full bg-green-100 text-green-700">● 已启用</span>
                    @else
                        <span class="text-xs px-2 py-0.5 rounded-full bg-gray-100 text-gray-500">○ 未启用</span>
                    @endif
                    <div class="flex gap-2">
                        <button wire:click="configure({{ $channel['id'] }})"
                            class="text-xs text-blue-500 hover:underline">配置</button>
                        <button wire:click="toggle({{ $channel['id'] }})"
                            class="text-xs {{ $channel['enabled'] ? 'text-red-500' : 'text-green-500' }} hover:underline">
                            {{ $channel['enabled'] ? '停用' : '启用' }}
                        </button>
                    </div>
                </div>
            </div>
        @endforeach
    </div>

    @if ($configChannel)
        <x-filament-panels::modal id="payment-config" wire:close="closeModal" visible>
            <x-slot:heading>
                配置: {{ $configChannel['name'] }}
            </x-slot>
            <form wire:submit="saveConfig">
                @foreach ($configFields as $field)
                    <div class="mb-3">
                        <label class="block text-xs font-semibold text-gray-600 mb-1">
                            {{ $field['label'] }}
                            @if ($field['required'] ?? false) <span class="text-red-500">*</span> @endif
                        </label>
                        @if ($field['type'] === 'textarea')
                            <textarea wire:model="configData.{{ $field['name'] }}" rows="2"
                                class="w-full text-sm border border-gray-200 rounded-lg p-2 font-mono"></textarea>
                        @elseif ($field['type'] === 'select')
                            <select wire:model="configData.{{ $field['name'] }}"
                                class="w-full text-sm border border-gray-200 rounded-lg p-2">
                                @foreach ($field['options'] ?? [] as $k => $v)
                                    <option value="{{ $k }}">{{ $v }}</option>
                                @endforeach
                            </select>
                        @else
                            <input type="{{ $field['type'] === 'password' ? 'password' : 'text' }}"
                                wire:model="configData.{{ $field['name'] }}"
                                class="w-full text-sm border border-gray-200 rounded-lg p-2" />
                        @endif
                    </div>
                @endforeach
                <div class="mt-2 p-2 bg-blue-50 rounded text-xs text-blue-600">
                    回调地址: {{ config('app.url') }}/api/payments/callback/{{ $configChannel['code'] }}
                </div>
                <div class="flex justify-end gap-2 mt-4">
                    <button type="button" wire:click="closeModal"
                        class="px-4 py-2 text-sm border border-gray-200 rounded-lg">取消</button>
                    <button type="submit"
                        class="px-4 py-2 text-sm bg-blue-500 text-white rounded-lg">保存</button>
                </div>
            </form>
        </x-filament-panels::modal>
    @endif
</x-filament-panels::page>
```

- [ ] **Step 2: 创建 PaymentChannels Page(Livewire)**

`app/Filament/Pages/PaymentChannels.php`:
```php
<?php

namespace App\Filament\Pages;

use App\Models\PaymentChannel;
use App\Support\PaymentService;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Livewire\Attributes\Computed;

class PaymentChannels extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-credit-card';

    protected static string $view = 'filament.pages.payment-channels';

    protected static ?string $navigationGroup = '系统';

    protected static ?string $navigationLabel = '支付通道';

    protected static ?int $navigationSort = 2;

    public $channels = [];
    public $configChannel = null;
    public $configFields = [];
    public $configData = [];

    public function mount(PaymentService $service): void
    {
        $this->loadChannels($service);
    }

    public function loadChannels(PaymentService $service): void
    {
        $this->channels = $service->getAllChannels()->map(function ($ch) {
            $driver = new ($ch->driver)();
            $info = $driver->getInfo();
            return [
                'id' => $ch->id,
                'name' => $ch->name,
                'code' => $ch->code,
                'icon' => $info['icon'] ?? '💳',
                'description' => $info['description'] ?? '',
                'enabled' => $ch->enabled,
            ];
        })->toArray();
    }

    public function configure($channelId): void
    {
        $ch = PaymentChannel::find($channelId);
        $driver = new ($ch->driver)();

        $this->configChannel = ['id' => $ch->id, 'name' => $ch->name, 'code' => $ch->code];
        $this->configFields = $driver->getConfigFields();
        $this->configData = $ch->config ?? [];
    }

    public function saveConfig(PaymentService $service): void
    {
        $service->saveChannelConfig($this->configChannel['id'], $this->configData);
        Notification::make()->success()->title('配置已保存')->send();
        $this->closeModal();
    }

    public function toggle($channelId, PaymentService $service): void
    {
        $ch = PaymentChannel::find($channelId);
        $service->toggleChannel($channelId, ! $ch->enabled);
        $this->loadChannels($service);
        Notification::make()->success()->title($ch->enabled ? '已停用' : '已启用')->send();
    }

    public function closeModal(): void
    {
        $this->configChannel = null;
        $this->configFields = [];
        $this->configData = [];
    }
}
```

- [ ] **Step 3: 注册 Page**

修改 `app/Providers/Filament/AdminPanelProvider.php`,在 `->pages([...])` 加:
```php
\App\Filament\Pages\PaymentChannels::class,
```

- [ ] **Step 4: 验证后台**

```bash
./vendor/bin/sail artisan optimize:clear
```
浏览器后台 → 系统 → 支付通道 → 见 6 张卡片 + 点配置弹 Modal + 启用/停用

- [ ] **Step 5: 提交**

```bash
git add app/Filament/Pages/ resources/views/filament/pages/ app/Providers/Filament/ && git commit -m "feat(filament): PaymentChannels card grid page + config modal"
```

---

## Task 7: 前台支付页改造(通道选择)

**Files:**
- Create: `storefront/src/api/payments.ts`
- Modify: `storefront/src/views/Pay.vue`

- [ ] **Step 1: payments API 封装**

`storefront/src/api/payments.ts`:
```ts
import request from './request'

export interface PaymentChannel {
  id: number; name: string; code: string; icon: string
}
export interface PaymentResult {
  type: 'redirect' | 'qrcode' | 'form'
  redirect_url?: string
  qrcode_content?: string
  form_html?: string
}
export const getChannels = () =>
  request.get<unknown, PaymentChannel[]>('/payments/channels')
export const createPayment = (orderNo: string, channelId: number) =>
  request.post<unknown, PaymentResult>('/payments/create', { order_no: orderNo, channel_id: channelId })
```

- [ ] **Step 2: Pay.vue 改造**

完全替换 `storefront/src/views/Pay.vue`:
```vue
<script setup lang="ts">
import { ref, onMounted } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { getChannels, createPayment, type PaymentChannel, type PaymentResult } from '@/api/payments'
import { mockPay } from '@/api/orders'

const route = useRoute()
const router = useRouter()
const orderNo = route.params.orderNo as string
const channels = ref<PaymentChannel[]>([])
const loading = ref(false)
const err = ref('')
const qrContent = ref('')

onMounted(async () => {
  try { channels.value = await getChannels() } catch (e) {}
})

async function pay(channelId: number) {
  loading.value = true
  err.value = ''
  qrContent.value = ''
  try {
    const res = await createPayment(orderNo, channelId)
    if (res.type === 'redirect' && res.redirect_url) {
      window.location.href = res.redirect_url
    } else if (res.type === 'qrcode' && res.qrcode_content) {
      qrContent.value = res.qrcode_content
    } else if (res.type === 'form' && res.form_html) {
      document.body.insertAdjacentHTML('beforeend', res.form_html)
      const form = document.forms[document.forms.length - 1] as HTMLFormElement
      form.submit()
    }
  } catch (e: any) {
    err.value = e?.response?.data?.message || '发起支付失败'
  } finally {
    loading.value = false
  }
}

async function mockPayOrder() {
  try {
    await mockPay(orderNo)
    alert('模拟支付成功')
    router.push('/orders/query')
  } catch (e: any) {
    err.value = e?.response?.data?.message || '失败'
  }
}
</script>

<template>
  <div class="max-w-md mx-auto px-4 py-8">
    <div class="bg-white rounded-card border border-gray-200 p-6">
      <h2 class="text-lg font-bold text-ink mb-2">订单待支付</h2>
      <div class="text-xs text-ink-muted mb-4">订单号:{{ orderNo }}</div>

      <!-- 通道选择 -->
      <div v-if="channels.length" class="space-y-2 mb-4">
        <div class="text-xs font-semibold text-ink-soft mb-2">选择支付方式</div>
        <button v-for="ch in channels" :key="ch.id" @click="pay(ch.id)" :disabled="loading"
          class="w-full flex items-center gap-3 p-3 border border-gray-200 rounded-card hover:border-primary transition disabled:opacity-50">
          <span class="text-xl">{{ ch.icon }}</span>
          <span class="text-sm font-medium text-ink">{{ ch.name }}</span>
        </button>
      </div>

      <!-- 二维码展示 -->
      <div v-if="qrContent" class="text-center py-4">
        <div class="text-xs text-ink-muted mb-2">请扫码支付</div>
        <div class="inline-block p-4 bg-white border-2 border-gray-200 rounded-lg">
          <div class="w-40 h-40 bg-gray-100 flex items-center justify-center text-xs text-gray-400 break-all p-2">{{ qrContent }}</div>
        </div>
        <div class="text-xs text-ink-muted mt-2">支付完成后自动跳转</div>
      </div>

      <div v-if="err" class="text-danger text-xs mt-3">{{ err }}</div>

      <!-- 模拟支付(开发备用) -->
      <div class="mt-4 pt-4 border-t border-gray-100">
        <button @click="mockPayOrder" class="w-full text-xs text-gray-400 hover:text-primary">
          (开发)模拟支付
        </button>
      </div>
    </div>
  </div>
</template>
```

- [ ] **Step 3: 构建验证 + 提交**

```bash
cd /Users/mac/Project/Php/ZCard/storefront && pnpm run build 2>&1 | tail -3
rm -rf dist
cd /Users/mac/Project/Php/ZCard && git add storefront/src/ && git commit -m "feat(storefront): payment channel selection + qrcode/redirect render"
```

---

## Task 8: 收尾验证

- [ ] **Step 1: 验证 6 通道就位**

```bash
./vendor/bin/sail artisan tinker --execute="
foreach(App\Models\PaymentChannel::all() as \$ch) {
    \$d = new (\$ch->driver)();
    echo \$ch->code.': '.\$d->getInfo()['name'].' fields='.count(\$d->getConfigFields()).\"\n\";
}
" 2>&1 | tail -8
```
Expected: 6 通道各显示名称+字段数

- [ ] **Step 2: 后台验证**

浏览器后台 → 系统 → 支付通道 → 6 张卡片 + 配置 Modal 动态字段 + 启用/停用

- [ ] **Step 3: 前台验证**

浏览器 :5173 → 下单 → 支付页 → 见通道列表(启用后) + 模拟支付仍可用

- [ ] **Step 4: 测试 + docs**

```bash
./vendor/bin/sail test 2>&1 | tail -3
git ls-files docs/ | head -1 && echo "BAD" || echo "GOOD"
git status --short
```

---

## 完成标准(对照 spec §9)

- [ ] PaymentDriver 接口 + PaymentResult
- [ ] 6 个 Driver 实现
- [ ] payment_channels 表 + Seeder
- [ ] PaymentService(createPayment/handleCallback/...)
- [ ] API(channels/create/callback)
- [ ] 后台卡片网格 + 配置 Modal
- [ ] 前台通道选择 + redirect/qrcode/form 渲染
- [ ] 保留 mock-pay

---

## 与 spec 的一致性

无偏差。spec §3(数据)、§4(Driver)、§5(Service)、§6(API)、§7(前台)、§8(后台)均有对应 Task。

**注意**:T3 的 AlipayDriver 代码中 `Pay::set	alipayConfig` 有制表符笔误,实现时改为 `Pay::setAlipayConfig()`。StripeDriver 需 `stripe/stripe-php` 包(T4 Step 5 安装)。PaypalDriver 用 HTTP 直接对接(无额外包)。
