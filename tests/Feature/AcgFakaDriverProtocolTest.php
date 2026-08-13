<?php

namespace Tests\Feature;

use App\Models\SupplySource;
use App\Supply\Drivers\AcgFakaDriver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * acg-faka 协议对接回归测试。
 *
 * 对照实现:/Users/mac/Project/Php/acg-faka
 * - 路由规则   kernel/Kernel.php:57-86(按 '/' 拆段,最后一段=方法名,其余=类名)
 * - 参数注入   kernel/Annotation/Collector.php:93(按**参数名**从 $_REQUEST 取)
 * - trade 返回 app/Service/Bind/Order.php  ['url','amount','tradeNo','secret']
 * - 多卡拼接   app/Service/Bind/Order.php:1209  $cardc .= $card->secret . PHP_EOL
 * - 查单       app/Controller/Shared/Commodity.php:351 query(string $tradeNo)
 *              → where("trade_no", $tradeNo),返回 ['secret','widget','status']
 * - 订单状态   0=未完成,1=已支付(orderSuccess)
 */
class AcgFakaDriverProtocolTest extends TestCase
{
    use RefreshDatabase;

    private function driver(): AcgFakaDriver
    {
        return new AcgFakaDriver(SupplySource::create([
            'name' => 'acg',
            'driver' => SupplySource::DRIVER_ACG_FAKA,
            'base_url' => 'https://acg.test',
            'credentials' => ['app_id' => '8', 'app_key' => 'kkk'],
            'status' => 'active',
        ]));
    }

    private function lastRequest(): ClientRequest
    {
        $sent = null;
        Http::assertSent(function (ClientRequest $r) use (&$sent) {
            $sent = $r;

            return true;
        });

        return $sent;
    }

    public function test_create_order_keeps_upstream_trade_no_and_amount(): void
    {
        Http::fake(['*' => Http::response([
            'code' => 200, 'msg' => 'success',
            'data' => ['url' => '', 'amount' => '12.34', 'tradeNo' => 'ACG20260804XYZ', 'secret' => 'CARD-A'],
        ], 200)]);

        $o = $this->driver()->createOrder([
            'product_code' => 'C1', 'quantity' => 1, 'downstream_order_no' => 'ORD-1',
        ]);

        // 上游标识必须是 acg-faka 的 trade_no,不是我们的单号 —— 否则查单永远查不到
        $this->assertSame('ACG20260804XYZ', $o->id);
        $this->assertSame(1234, $o->amount, '成本应按 元→分 记录');
        $this->assertSame('delivered', $o->status);
        $this->assertSame(['CARD-A'], $o->fulfillment->cards);
    }

    public function test_create_order_splits_multiple_cards(): void
    {
        Http::fake(['*' => Http::response([
            'code' => 200,
            'data' => ['amount' => '30.00', 'tradeNo' => 'T2', 'secret' => "CARD-A\nCARD-B\nCARD-C"],
        ], 200)]);

        $o = $this->driver()->createOrder([
            'product_code' => 'C1', 'quantity' => 3, 'downstream_order_no' => 'ORD-2',
        ]);

        // acg-faka 用 PHP_EOL 把 3 张卡拼成一个字符串;不拆的话 writeCards 只会建 1 条发货记录
        $this->assertSame(['CARD-A', 'CARD-B', 'CARD-C'], $o->fulfillment->cards);
    }

    public function test_get_order_uses_method_style_path_with_trade_no_in_body(): void
    {
        Http::fake(['*' => Http::response([
            'code' => 200,
            'data' => ['secret' => "CARD-A\nCARD-B", 'widget' => null, 'status' => 1],
        ], 200)]);

        $o = $this->driver()->getOrder('ACG-TRADE-9');
        $req = $this->lastRequest();

        // 路径最后一段必须是方法名 query;拼成 /query/{no} 会被 Kernel 当成类名 → 404
        $this->assertSame('https://acg.test/shared/commodity/query', $req->url());
        parse_str($req->body(), $form);
        $this->assertSame('ACG-TRADE-9', $form['tradeNo'] ?? null, 'tradeNo 必须走 body(按参数名注入)');

        $this->assertSame('delivered', $o->status);
        $this->assertSame(['CARD-A', 'CARD-B'], $o->fulfillment->cards);
    }

    public function test_get_order_unpaid_is_pending(): void
    {
        Http::fake(['*' => Http::response([
            'code' => 200, 'data' => ['secret' => '', 'status' => 0],
        ], 200)]);

        $o = $this->driver()->getOrder('ACG-TRADE-X');

        $this->assertSame('pending', $o->status);
        $this->assertNull($o->fulfillment);
    }

    public function test_get_order_requires_paid_status_before_delivering(): void
    {
        // 防御性:即使上游异常地在未支付态回了 secret,也不能凭 fulfillment 就写卡
        Http::fake(['*' => Http::response([
            'code' => 200, 'data' => ['secret' => 'CARD-A', 'status' => 0],
        ], 200)]);

        $o = $this->driver()->getOrder('ACG-TRADE-Y');

        $this->assertSame('pending', $o->status);
        $this->assertNull($o->fulfillment, 'status!=1 时不得构造 delivered 的 fulfillment');
    }

    public function test_list_products_maps_stock_from_items(): void
    {
        Http::fake(['*' => Http::response([
            'code' => 200,
            'data' => [[
                'id' => 5, 'name' => '分类', 'children' => [
                    ['code' => 'A', 'name' => '自动发货', 'price' => '10.00', 'factory_price' => '8.00', 'stock' => 42],
                    ['code' => 'B', 'name' => '手动发货', 'price' => '20.00', 'factory_price' => '15.00'],
                ],
            ]],
        ], 200)]);

        $items = $this->driver()->listProducts(null, 1)['items'];

        $this->assertSame(42, $items[0]->stockQuantity, 'items 带 stock 时应透传');
        $this->assertSame(-1, $items[1]->stockQuantity, '不带 stock 视为无限');
        $this->assertSame(1000, $items[0]->price);
        $this->assertSame(800, $items[0]->factoryPrice);
    }

    public function test_list_products_uses_real_public_share_url_instead_of_api_code(): void
    {
        Http::fake([
            'https://acg.test/shared/commodity/items' => Http::response([
                'code' => 200,
                'data' => [
                    [
                        'id' => 5, 'name' => '分类一', 'children' => [
                            ['id' => 101, 'code' => 'RANDOM-CODE-A', 'name' => '商品A', 'price' => '10.00'],
                        ],
                    ],
                    [
                        'id' => 9, 'name' => '分类二', 'children' => [
                            ['id' => 202, 'code' => 'RANDOM-CODE-B', 'name' => '商品B', 'price' => '20.00'],
                        ],
                    ],
                ],
            ]),
            'https://acg.test/user/api/index/commodityDetail*' => Http::response([
                'code' => 200,
                'data' => ['share_url' => 'https://acg.test?cid=5&mid=101'],
            ]),
        ]);

        $items = $this->driver()->listProducts(null, 1)['items'];

        $this->assertSame('RANDOM-CODE-A', $items[0]->code, '对接仍必须使用 API code');
        $this->assertSame('https://acg.test/?cid=5&mid=101', $items[0]->productUrl);
        $this->assertSame('https://acg.test/?cid=9&mid=202', $items[1]->productUrl);
        Http::assertSentCount(2); // 商品列表接口 + 一次分享链接规则探测，不得逐商品请求。
    }

    public function test_list_products_supports_new_item_share_url(): void
    {
        Http::fake([
            'https://acg.test/shared/commodity/items' => Http::response([
                'code' => 200,
                'data' => [[
                    'id' => 5, 'name' => '分类', 'children' => [
                        ['id' => 101, 'code' => 'CODE-A', 'name' => '商品A', 'price' => '10.00'],
                        ['id' => 102, 'code' => 'CODE-B', 'name' => '商品B', 'price' => '20.00'],
                    ],
                ]],
            ]),
            'https://acg.test/user/api/index/commodityDetail*' => Http::response([
                'code' => 200,
                'data' => ['share_url' => 'https://acg.test/item/101'],
            ]),
        ]);

        $items = $this->driver()->listProducts(null, 1)['items'];

        $this->assertSame('https://acg.test/item/101', $items[0]->productUrl);
        $this->assertSame('https://acg.test/item/102', $items[1]->productUrl);
    }
}
