<?php

namespace Tests\Feature;

use App\Models\SupplySource;
use App\Supply\Drivers\DujiaoNextDriver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * dujiao-next 上游协议回归测试(基于参考代码 internal/modules/upstreamapi)。
 *
 * 覆盖复查发现的对接问题:
 * 1. ping 响应是 site_name(不是 name)
 * 2. 库存/价格在 SKU 层,mapProduct 需填充 skus + stockQuantity
 * 3. 下单按 sku_id(商品第一个启用 SKU),不是商品 id
 * 4. 回调签名 path 固定 /api/v1/upstream/callback(非接收路径)
 */
class DujiaoNextDriverProtocolTest extends TestCase
{
    use RefreshDatabase;

    private const API_KEY = 'dj_api_key';

    private const API_SECRET = 'dj_api_secret';

    private function driver(): DujiaoNextDriver
    {
        $source = SupplySource::create([
            'name' => 'dujiao',
            'driver' => 'dujiao_next',
            'base_url' => 'https://dujiao.test',
            'credentials' => ['api_key' => self::API_KEY, 'api_secret' => self::API_SECRET],
            'status' => 'active',
        ]);

        return new DujiaoNextDriver($source);
    }

    public function test_ping_reads_site_name(): void
    {
        Http::fake([
            'dujiao.test/*' => Http::response([
                'ok' => true,
                'site_name' => '独角数卡',
                'balance' => '12.50',
                'currency' => 'CNY',
            ]),
        ]);

        $r = $this->driver()->ping();
        $this->assertTrue($r['connected']);
        $this->assertSame('独角数卡', $r['name']); // 上游字段是 site_name
        $this->assertSame(1250, $r['balance']);
    }

    public function test_map_product_reads_skus_and_stock(): void
    {
        Http::fake([
            'dujiao.test/*' => Http::response([
                'ok' => true,
                'product' => [
                    'id' => 42,
                    'title' => ['zh-CN' => 'Gmail 账号', 'en-US' => 'Gmail account'],
                    'description' => ['zh-CN' => '商品简介'],
                    'content' => ['zh-CN' => '<h2>完整教程</h2><p>登录后修改密码</p>'],
                    'price_amount' => '6.50',
                    'wholesale_prices' => [['min_quantity' => 1, 'unit_price' => '5.00']],
                    'category_id' => 3,
                    'images' => ['https://x/a.png'],
                    'is_active' => true,
                    'skus' => [
                        ['id' => 1001, 'sku_code' => 'SKU-A', 'price_amount' => '6.50', 'stock_status' => 'in_stock', 'stock_quantity' => 12, 'is_active' => true],
                        ['id' => 1002, 'sku_code' => 'SKU-B', 'price_amount' => '6.80', 'stock_status' => 'out_of_stock', 'stock_quantity' => 0, 'is_active' => true],
                        ['id' => 1003, 'sku_code' => 'SKU-C', 'price_amount' => '7.00', 'stock_status' => 'in_stock', 'stock_quantity' => 5, 'is_active' => false],
                    ],
                ],
            ]),
        ]);

        $p = $this->driver()->getProduct('42');
        $this->assertNotNull($p);
        $this->assertSame('42', $p->code);
        $this->assertSame('Gmail 账号', $p->name);
        $this->assertStringContainsString('完整教程', $p->description);
        $this->assertSame(650, $p->price);          // 商品级 price_amount 元→分
        $this->assertSame(500, $p->factoryPrice);   // wholesale_prices[0]
        $this->assertCount(2, $p->skus);            // 只保留启用 SKU(1003 is_active=false 排除)
        $this->assertSame('1001', $p->skus[0]['code']);   // 第一个启用 SKU 的 id
        $this->assertSame(12, $p->stockQuantity);   // 库存来自第一个启用 SKU
    }

    public function test_list_categories_reads_localized_name(): void
    {
        Http::fake([
            'dujiao.test/*' => Http::response([
                'ok' => true,
                'categories' => [[
                    'id' => 3,
                    'parent_id' => 0,
                    'name' => ['zh-CN' => '账号专区', 'en-US' => 'Accounts'],
                    'sort_order' => 10,
                ]],
            ]),
        ]);

        $categories = $this->driver()->listCategories();

        $this->assertCount(1, $categories);
        $this->assertSame('账号专区', $categories[0]->name);
    }

    public function test_create_order_uses_sku_id_of_first_active_sku(): void
    {
        Http::fake([
            'dujiao.test/*' => function (ClientRequest $request) {
                if (str_contains($request->url(), '/products/42')) {
                    // 商品详情
                    return Http::response([
                        'ok' => true,
                        'product' => [
                            'id' => 42,
                            'title' => 'Gmail',
                            'price_amount' => '6.50',
                            'is_active' => true,
                            'skus' => [
                                ['id' => 1001, 'sku_code' => 'SKU-A', 'price_amount' => '6.50', 'stock_quantity' => 12, 'is_active' => true],
                            ],
                        ],
                    ]);
                }

                // 下单:断言 body 用 sku_id(1001)而非商品 id(42)
                $body = $request->data();
                $this->assertSame(1001, $body['sku_id'] ?? null);
                $this->assertSame(2, $body['quantity'] ?? null);
                $this->assertSame('Z-ORDER-1', $body['downstream_order_no'] ?? null);

                return Http::response([
                    'ok' => true,
                    'order_id' => 888,
                    'order_no' => 'NO888',
                    'status' => 'paid',
                    'amount' => '13.00',
                    'currency' => 'CNY',
                ]);
            },
        ]);

        $o = $this->driver()->createOrder([
            'product_code' => '42',
            'quantity' => 2,
            'downstream_order_no' => 'Z-ORDER-1',
        ]);
        $this->assertSame('888', $o->id);
        $this->assertSame('paid', $o->status);
        $this->assertSame(1300, $o->amount);
    }

    public function test_verify_callback_uses_fixed_signature_path(): void
    {
        // 按上游签名约定构造回调:path 固定 /api/v1/upstream/callback
        $body = json_encode([
            'event' => 'order.paid',
            'order_id' => 888,
            'order_no' => 'NO888',
            'downstream_order_no' => 'Z-ORDER-1',
            'status' => 'delivered',
            'fulfillment' => ['type' => 'auto', 'status' => 'delivered', 'payload' => 'CARD-123'],
            'timestamp' => time(),
        ]);
        $ts = (string) time();
        $sign = hash_hmac('sha256', implode("\n", ['POST', '/api/v1/upstream/callback', $ts, md5($body)]), self::API_SECRET);

        $request = Request::create('/api/supply/callback', 'POST', [], [], [], [], $body);
        $request->headers->set('Dujiao-Next-Api-Key', self::API_KEY);
        $request->headers->set('Dujiao-Next-Timestamp', $ts);
        $request->headers->set('Dujiao-Next-Signature', $sign);

        $result = $this->driver()->verifyCallback($request);
        $this->assertNotNull($result); // 路径不匹配会返回 null
        $this->assertSame('888', $result['upstream_order_id']);
        $this->assertSame(['CARD-123'], $result['cards']);
        $this->assertSame('Z-ORDER-1', $result['downstream_order_no']);
    }
}
