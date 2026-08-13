<?php

namespace Tests\Feature;

use App\Models\Merchant;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentChannel;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * 微信支付 V3 回调验签(安全审计 H4):
 * 回调必须携带原始 body + 全部请求头(Wechatpay-* 四头)交 SDK 验签;
 * 验签/解密/金额核对任一失败都必须 fail-closed,订单保持 pending。
 */
class WechatPayCallbackTest extends TestCase
{
    use RefreshDatabase;

    private string $privateKey = '';

    private string $publicCertPath = '';

    private const APIV3 = '0123456789abcdef0123456789abcdef';

    protected function setUp(): void
    {
        parent::setUp();

        $res = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $this->assertNotFalse($res);
        openssl_pkey_export($res, $this->privateKey);
        $details = openssl_pkey_get_details($res);
        $this->publicCertPath = tempnam(sys_get_temp_dir(), 'wxpub');
        file_put_contents($this->publicCertPath, (string) $details['key']);
    }

    protected function tearDown(): void
    {
        @unlink($this->publicCertPath);
        parent::tearDown();
    }

    private function makeChannel(): PaymentChannel
    {
        return PaymentChannel::create([
            'name' => '微信测试',
            'code' => 'wechat',
            'driver' => 'App\\Payment\\Drivers\\WechatPayDriver',
            'enabled' => true,
            'config' => [
                'mch_id' => '1900000001',
                'app_id' => 'wx-test-appid',
                'mch_secret_key' => self::APIV3,
                'mch_secret_cert' => $this->privateKey,
                'mch_public_cert_path' => $this->publicCertPath,
                'wechat_platform_serial' => 'TEST-SERIAL',
                'mode' => 'normal',
            ],
        ]);
    }

    /** 构造与微信一致的加密 resource(算法 AES-256-GCM / associated_data=transaction) */
    private function buildBody(string $orderNo, int $amountFen): array
    {
        $plain = json_encode([
            'out_trade_no' => $orderNo,
            'transaction_id' => '420000'.time(),
            'trade_state' => 'SUCCESS',
            'amount' => ['total' => $amountFen, 'payer_total' => $amountFen, 'currency' => 'CNY'],
            'mchid' => '1900000001',
            'appid' => 'wx-test-appid',
        ]);

        // IV 必须是 12 字节且为合法 UTF-8(ASCII):JSON 序列化要求合法 UTF-8,
        // 而 yansongda/pay v3 将 resource.nonce 原样作为 openssl IV 传入。
        $iv = '123456789012';
        $cipher = openssl_encrypt($plain, 'aes-256-gcm', self::APIV3, OPENSSL_RAW_DATA, $iv, $tag, 'transaction');

        return [
            'id' => 'evt_'.bin2hex(random_bytes(4)),
            'event_type' => 'TRANSACTION.SUCCESS',
            'resource' => [
                'algorithm' => 'AEAD_AES_256_GCM',
                'ciphertext' => base64_encode($cipher.$tag),
                // 注意:yansongda/pay v3 将 resource.nonce 原样传给 openssl 作 IV,
                // 因此必须使用原始 12 字节 nonce(而非 base64)才能与加密侧匹配。
                'nonce' => $iv,
                'associated_data' => 'transaction',
                'original_type' => 'transaction',
            ],
        ];
    }

    private function makeOrder(string $orderNo, int $amountFen): Order
    {
        $merchant = Merchant::firstOrCreate(
            ['slug' => 't'],
            ['user_id' => User::factory()->create()->id, 'name' => 'T', 'status' => 1, 'commission_rate' => 0],
        );
        $product = Product::create([
            'merchant_id' => $merchant->id,
            'name' => '测试商品',
            'slug' => 'test-product-'.bin2hex(random_bytes(4)),
            'price' => $amountFen,
            'factory_price' => 0,
            'status' => 1,
        ]);

        $order = Order::create([
            'order_no' => $orderNo,
            'merchant_id' => $merchant->id,
            'product_id' => $product->id,
            'quantity' => 1,
            'amount' => $amountFen,
            'cost' => 0,
            'status' => 'pending',
            'delivery_status' => 'pending',
            'fulfillment_type_snapshot' => 'manual',
        ]);
        Payment::create([
            'order_id' => $order->id,
            'channel' => 'wechat',
            'amount' => $amountFen,
            'charged_amount' => $amountFen,
            'charged_currency' => 'CNY',
            'status' => 'pending',
        ]);

        return $order;
    }

    private function postRawCallback(string $body, array $headers): TestResponse
    {
        // call() 使用 serverVariables 传头(不读 defaultHeaders),需手动转成 HTTP_* server 变量。
        $server = $this->transformHeadersToServerVars([
            ...$headers,
            'Content-Type' => 'application/json',
        ]);

        return $this->call('POST', '/api/payments/callback/wechat', [], [], [], $server, $body);
    }

    public function test_valid_signature_marks_order_paid(): void
    {
        $this->makeChannel();
        $order = $this->makeOrder('ORD'.time().'AAA', 12345);

        $body = json_encode($this->buildBody($order->order_no, 12345), JSON_UNESCAPED_SLASHES);
        $timestamp = (string) time();
        $nonce = bin2hex(random_bytes(8));
        $content = $timestamp."\n".$nonce."\n".$body."\n";
        openssl_sign($content, $sig, $this->privateKey, 'sha256WithRSAEncryption');

        $this->postRawCallback($body, [
            'Wechatpay-Serial' => 'TEST-SERIAL',
            'Wechatpay-Timestamp' => $timestamp,
            'Wechatpay-Nonce' => $nonce,
            'Wechatpay-Signature' => base64_encode($sig),
        ])->assertOk()->assertSee('success');

        $this->assertSame('paid', $order->fresh()->status);
        $this->assertSame('success', Payment::where('order_id', $order->id)->value('status'));
    }

    public function test_tampered_body_is_rejected_and_order_stays_pending(): void
    {
        $this->makeChannel();
        $order = $this->makeOrder('ORD'.time().'BBB', 12345);

        // 用合法 body 签名,但传输时篡改金额字段
        $goodBody = json_encode($this->buildBody($order->order_no, 12345), JSON_UNESCAPED_SLASHES);
        $tampered = json_decode($goodBody, true);
        $tampered['resource']['ciphertext'] = base64_encode(random_bytes(40)); // 破坏密文
        $tamperedBody = json_encode($tampered, JSON_UNESCAPED_SLASHES);

        $timestamp = (string) time();
        $nonce = bin2hex(random_bytes(8));
        $content = $timestamp."\n".$nonce."\n".$goodBody."\n";
        openssl_sign($content, $sig, $this->privateKey, 'sha256WithRSAEncryption');

        $this->postRawCallback($tamperedBody, [
            'Wechatpay-Serial' => 'TEST-SERIAL',
            'Wechatpay-Timestamp' => $timestamp,
            'Wechatpay-Nonce' => $nonce,
            'Wechatpay-Signature' => base64_encode($sig),
        ])->assertOk()->assertSee('fail');

        $this->assertSame('pending', $order->fresh()->status);
    }

    public function test_amount_mismatch_is_rejected(): void
    {
        $this->makeChannel();
        $order = $this->makeOrder('ORD'.time().'CCC', 12345);

        // 签名为真但金额不对(上游报 1 分)——金额核对必须拒绝,防"少付多拿"。
        $body = json_encode($this->buildBody($order->order_no, 1), JSON_UNESCAPED_SLASHES);
        $timestamp = (string) time();
        $nonce = bin2hex(random_bytes(8));
        $content = $timestamp."\n".$nonce."\n".$body."\n";
        openssl_sign($content, $sig, $this->privateKey, 'sha256WithRSAEncryption');

        $this->postRawCallback($body, [
            'Wechatpay-Serial' => 'TEST-SERIAL',
            'Wechatpay-Timestamp' => $timestamp,
            'Wechatpay-Nonce' => $nonce,
            'Wechatpay-Signature' => base64_encode($sig),
        ])->assertOk()->assertSee('fail');

        $this->assertSame('pending', $order->fresh()->status);
    }
}
