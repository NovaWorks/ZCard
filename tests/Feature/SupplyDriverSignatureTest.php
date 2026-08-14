<?php

namespace Tests\Feature;

use App\Models\SupplierAccount;
use App\Models\SupplySource;
use App\Supply\Drivers\DujiaoNextDriver;
use App\Supply\Drivers\ZCardDriver;
use App\Supply\HmacSigner;
use App\Support\StorefrontConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * 上游驱动「签名字节 == 发送字节」回归测试。
 *
 * 背景:驱动曾把已经 md5 过的值再传给 signedHeaders(),而 signedHeaders() 内部又 md5 一次,
 * 结果签的是 md5(md5(body));服务端 SupplyAuth 算的是 md5(原始 body) → 恒定 invalid_signature。
 * 空 body 场景同样错位(客户端实际发的是 "[]" 而签名按 "" 计算)。
 *
 * ZCard 侧用「截获 + 回放」做闭环:Http::fake() 拿到驱动真实发出的签名头与原始 body,
 * 再原样喂进本应用的 /api/supply/*,验证 SupplyAuth 能通过 —— 两端口径必须一致才会绿。
 * dujiao-next 是外部系统无法闭环,退而断言签名确实是对「实际发出的 body」做单次 md5。
 */
class SupplyDriverSignatureTest extends TestCase
{
    use RefreshDatabase;

    private const API_KEY = 'ak_sig_test';

    private const API_SECRET = 'sk_sig_test';

    protected function setUp(): void
    {
        parent::setUp();

        StorefrontConfig::setMany([
            'supply_enabled' => true,
            'supply_nonce_store' => 'cache',
            'supply_timestamp_skew' => 300,
        ]);

        // 下游账号:回放时由 SupplyAuth 用它的 api_secret 验签(测试里存明文)
        SupplierAccount::create([
            'name' => 'downstream',
            'api_key' => self::API_KEY,
            'api_secret' => self::API_SECRET,
            'balance' => 100000,
            'status' => 'active',
            'approved' => true,
        ]);
    }

    private function source(string $driver): SupplySource
    {
        return SupplySource::create([
            'name' => 'upstream',
            'driver' => $driver,
            'base_url' => 'https://upstream.test',
            'credentials' => ['api_key' => self::API_KEY, 'api_secret' => self::API_SECRET],
            'status' => 'active',
        ]);
    }

    /**
     * 执行驱动动作并截获它真实发出的那个 HTTP 请求。
     */
    private function captureSent(callable $action, array $fakeJson = ['ok' => true]): ClientRequest
    {
        Http::fake(['*' => Http::response($fakeJson, 200)]);

        $action();

        $sent = null;
        Http::assertSent(function (ClientRequest $request) use (&$sent) {
            $sent = $request;

            return true;
        });

        $this->assertNotNull($sent, '驱动未发出任何 HTTP 请求');

        return $sent;
    }

    /**
     * 把驱动发出的请求(签名头 + 原始 body)原样回放进本应用。
     */
    private function replay(ClientRequest $sent, string $path)
    {
        $server = ['CONTENT_TYPE' => 'application/json'];
        foreach ($sent->headers() as $name => $values) {
            $server['HTTP_'.strtoupper(str_replace('-', '_', $name))] = $values[0];
        }

        return $this->call('POST', $path, [], [], [], $server, $sent->body());
    }

    public function test_zcard_ping_signature_passes_supply_auth(): void
    {
        $sent = $this->captureSent(fn () => (new ZCardDriver($this->source(SupplySource::DRIVER_ZCARD)))->ping());

        $this->replay($sent, '/api/supply/ping')->assertOk();
    }

    public function test_zcard_create_order_signature_passes_supply_auth(): void
    {
        $driver = new ZCardDriver($this->source(SupplySource::DRIVER_ZCARD));

        $sent = $this->captureSent(
            fn () => $driver->createOrder([
                'product_code' => '1',
                'quantity' => 1,
                'downstream_order_no' => 'ORD-SIG-1',
            ]),
            ['supply_order_id' => 1, 'amount' => 100, 'fulfillment' => ['status' => 'delivered', 'cards' => ['CARD-1']]],
        );

        // 带 body 的请求最容易踩双重哈希;这里只关心「过了验签」,
        // 后续业务失败(商品不存在等)不是本测试的关注点。
        $resp = $this->replay($sent, '/api/supply/orders');

        $this->assertNotSame('invalid_signature', $resp->json('error_code'));
        $this->assertNotSame(401, $resp->status(), '签名被 SupplyAuth 拒绝: '.$resp->getContent());
    }

    public function test_zcard_signature_is_over_the_exact_bytes_sent(): void
    {
        $driver = new ZCardDriver($this->source(SupplySource::DRIVER_ZCARD));

        $sent = $this->captureSent(
            fn () => $driver->createOrder([
                'product_code' => '7',
                'quantity' => 2,
                'downstream_order_no' => 'ORD-SIG-2',
            ]),
            ['supply_order_id' => 2, 'amount' => 200, 'fulfillment' => ['status' => 'pending', 'cards' => []]],
        );

        $query = parse_url($sent->url(), PHP_URL_QUERY) ?: '';
        $expected = HmacSigner::sign(self::API_SECRET, HmacSigner::buildSignStringWithQuery(
            'POST',
            '/api/supply/orders',
            $query, // v1.12.90+:签名串追加 query md5 段(POST 通常为空)
            $sent->header('X-Supply-Timestamp')[0],
            $sent->header('X-Supply-Nonce')[0],
            md5($sent->body()), // 关键:对「实际发出的 body」做且只做一次 md5
        ));

        $this->assertSame($expected, $sent->header('X-Supply-Signature')[0]);
    }

    public function test_zcard_delivered_order_parses_paid_instructions_without_requiring_cards(): void
    {
        Http::fake(['*' => Http::response([
            'supply_order_id' => 3,
            'amount' => 300,
            'fulfillment' => [
                'status' => 'delivered',
                'cards' => [],
                'instructions' => '<p>付款后教程</p>',
            ],
        ], 200)]);

        $order = (new ZCardDriver($this->source(SupplySource::DRIVER_ZCARD)))->createOrder([
            'product_code' => '8',
            'quantity' => 1,
            'downstream_order_no' => 'ORD-INSTRUCTIONS',
        ]);

        $this->assertNotNull($order->fulfillment);
        $this->assertSame([], $order->fulfillment->cards);
        $this->assertSame('<p>付款后教程</p>', $order->fulfillment->instructions);
    }

    public function test_dujiao_next_signature_is_over_the_exact_bytes_sent(): void
    {
        $driver = new DujiaoNextDriver($this->source(SupplySource::DRIVER_DUJIAO_NEXT));

        $sent = $this->captureSent(
            fn () => $driver->createOrder([
                'product_code' => '9',
                'quantity' => 1,
                'downstream_order_no' => 'ORD-SIG-3',
            ]),
            ['order_id' => 'U-1', 'status' => 'pending', 'amount' => 1.5],
        );

        $expected = hash_hmac('sha256', implode("\n", [
            'POST',
            '/api/v1/upstream/orders',
            $sent->header('Dujiao-Next-Timestamp')[0],
            md5($sent->body()),
        ]), self::API_SECRET);

        $this->assertSame($expected, $sent->header('Dujiao-Next-Signature')[0]);
    }

    public function test_dujiao_next_ping_signs_empty_body(): void
    {
        $driver = new DujiaoNextDriver($this->source(SupplySource::DRIVER_DUJIAO_NEXT));

        $sent = $this->captureSent(fn () => $driver->ping(), ['ok' => true, 'name' => 'up', 'balance' => 1]);

        // 无 body 的请求必须真的发空串,而不是 "[]"
        $this->assertSame('', $sent->body());

        $expected = hash_hmac('sha256', implode("\n", [
            'POST',
            '/api/v1/upstream/ping',
            $sent->header('Dujiao-Next-Timestamp')[0],
            md5(''),
        ]), self::API_SECRET);

        $this->assertSame($expected, $sent->header('Dujiao-Next-Signature')[0]);
    }
}
