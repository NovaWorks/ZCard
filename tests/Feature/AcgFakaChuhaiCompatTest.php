<?php

namespace Tests\Feature;

use App\Models\SupplySource;
use App\Supply\Drivers\AcgFakaDriver;
use App\Supply\Exceptions\UpstreamRequestException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * 出海同款版(acg-3.1.1Max)兼容：
 * 无 /shared/commodity/stock 路由、item 只认 sharedCode、
 * 库存走 /shared/commodity/inventory(参数 sharedCode、返回 count)、
 * 未知路由返回 HTTP 200 的 JS+Base64 包装 404 页(非 404 状态码)。
 */
class AcgFakaChuhaiCompatTest extends TestCase
{
    use RefreshDatabase;

    private function makeSource(array $settings = []): SupplySource
    {
        return SupplySource::create([
            'name' => '出海tg', 'driver' => 'acg_faka',
            'base_url' => 'https://chuhai.example.com',
            'credentials' => ['app_id' => 'aid', 'app_key' => 'key'],
            'status' => 'active', 'settings' => $settings,
        ]);
    }

    /**
     * 复刻 chuhaitg.com 真实 404 页形态：JS atob + Base64，
     * 且 Base64 载荷带随机 hex 前缀，保证内容不是 3 字节对齐 ——
     * 旧的「正文中找固定 base64 片段」检测对它无效，必须整体解码。
     */
    private function chuhai404Page(): string
    {
        $payload = md5((string) mt_rand()).'<!doctype html><html><head><meta charset="utf-8">'
            .'<title>404 Not Found</title></head><body>页面不存在</body></html>';

        return '<script>var _0x18eb=["write"];document.write(atob("'
            .base64_encode($payload).'"));</script>';
    }

    public function test_stock_sync_falls_back_to_inventory_endpoint(): void
    {
        $codes = ['C1', 'C2', 'C3'];
        $hits = ['stock' => 0, 'item' => 0, 'inventory' => 0];
        $inventoryCodes = [];
        Http::fake(function (Request $request) use ($codes, &$hits, &$inventoryCodes) {
            $url = $request->url();
            if (str_ends_with($url, '/items')) {
                // 出海版 items 不带 stock 字段 → 全部商品都要补查
                return Http::response(['code' => 200, 'data' => [[
                    'id' => 1,
                    'name' => '分类A',
                    'children' => array_map(fn (string $code) => [
                        'id' => crc32($code), 'code' => $code, 'name' => "商品{$code}",
                        'price' => '10.00', 'delivery_way' => 1,
                    ], $codes),
                ]]]);
            }
            if (str_ends_with($url, '/stock')) {
                $hits['stock']++;

                return Http::response($this->chuhai404Page(), 200, ['Content-Type' => 'text/html; charset=UTF-8']);
            }
            if (str_ends_with($url, '/item')) {
                $hits['item']++;
                $data = $request->data();
                // 出海版 item 只认 sharedCode；用 code 请求 → 业务报错
                if (! isset($data['sharedCode'])) {
                    return Http::response(['code' => 1001, 'msg' => '商品代码不能为空']);
                }

                return Http::response(['code' => 200, 'data' => ['stock' => 1]]);
            }
            if (str_ends_with($url, '/inventory')) {
                $hits['inventory']++;
                $data = $request->data();
                $code = (string) ($data['sharedCode'] ?? '');
                $inventoryCodes[] = $code;
                // 校验签名口径：非空业务参数 + app_id，ksort 后 md5(...&key=app_key)
                $signing = ['app_id' => 'aid', 'sharedCode' => $code];
                ksort($signing);
                $this->assertSame(
                    md5(urldecode(http_build_query($signing)).'&key=key'),
                    $data['sign'] ?? null,
                );

                return Http::response([
                    'code' => 200,
                    'data' => ['count' => (int) substr($code, 1) * 3],
                ]);
            }

            return Http::response('unexpected endpoint', 500);
        });

        $result = (new AcgFakaDriver($this->makeSource(['schedule' => [
            'stock_concurrency' => 2,
            'stock_request_delay_ms' => 0,
        ]])))->listProducts(null, 1, fetchStock: true);

        $this->assertSame([3, 6, 9], collect($result['items'])->pluck('stockQuantity')->all());
        $this->assertSame($codes, $inventoryCodes, '探测 + 批量补查都必须走 inventory，参数为 sharedCode');
        $this->assertSame(1, $hits['stock'], '每个商品都不得重复撞不存在的 stock 路由');
        $this->assertSame(1, $hits['item'], 'item 只在探测期撞一次参数名不符');
        $this->assertSame(3, $hits['inventory']);
    }

    public function test_get_product_retries_with_shared_code_param(): void
    {
        Http::fake(function (Request $request) {
            if (! str_ends_with($request->url(), '/item')) {
                return Http::response('unexpected endpoint', 500);
            }
            $data = $request->data();
            if (! isset($data['sharedCode'])) {
                return Http::response(['code' => 1001, 'msg' => '商品代码不能为空']);
            }

            return Http::response(['code' => 200, 'data' => [
                'code' => 'C9', 'name' => '出海商品', 'price' => '6.60', 'user_price' => '5.50',
                'status' => 1, 'stock' => 4, 'id' => 9,
            ]]);
        });

        $product = (new AcgFakaDriver($this->makeSource()))->getProduct('C9');

        $this->assertNotNull($product);
        $this->assertSame('C9', $product->code);
        $this->assertSame(660, $product->price);
        $this->assertSame(550, $product->factoryPrice);
        $this->assertSame(4, $product->stockQuantity);
        $itemRequests = Http::recorded()->filter(
            fn (array $entry) => str_ends_with($entry[0]->url(), '/item'),
        )->map(fn (array $entry) => array_keys($entry[0]->data()))->all();
        $this->assertSame([['code', 'app_id', 'sign'], ['sharedCode', 'app_id', 'sign']], $itemRequests);
    }

    public function test_all_candidates_rejected_prefers_business_error_over_route_error(): void
    {
        Http::fake(function (Request $request) {
            $url = $request->url();
            if (str_ends_with($url, '/stock')) {
                return Http::response($this->chuhai404Page(), 200, ['Content-Type' => 'text/html; charset=UTF-8']);
            }

            // 路由存在但密钥错误(SharedValidation 先于控制器执行)
            return Http::response(['code' => 1001, 'msg' => '密钥错误']);
        });

        try {
            (new AcgFakaDriver($this->makeSource()))->getStock('C1');
            $this->fail('所有候选失败时必须抛出更可诊断的业务错误');
        } catch (UpstreamRequestException $e) {
            $this->assertSame('UPSTREAM_BUSINESS_ERROR', $e->errorCode);
            $this->assertStringContainsString('密钥错误', $e->getMessage());
        }
    }
}
