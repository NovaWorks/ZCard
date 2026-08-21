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
 * acg-faka 驱动库存补查(fetchStock=true):
 * readonly DTO 重新构造(兼容 PHP 8.3,不用 clone 赋值),库存准确写入。
 */
class AcgFakaStockFetchTest extends TestCase
{
    use RefreshDatabase;

    private function makeSource(array $settings = []): SupplySource
    {
        return SupplySource::create([
            'name' => 'acg-faka 上游', 'driver' => 'acg_faka',
            'base_url' => 'https://up.example.com',
            'credentials' => ['app_id' => 'aid', 'app_key' => 'key'],
            'status' => 'active', 'settings' => $settings,
        ]);
    }

    private function itemsResponse(array $codes): array
    {
        return [
            'code' => 200,
            'data' => [[
                'id' => 1,
                'name' => '分类A',
                'children' => array_map(fn (string $code) => [
                    'id' => crc32($code),
                    'code' => $code,
                    'name' => "手动发货商品{$code}",
                    'price' => '100.00',
                    'delivery_way' => 1,
                    'share_url' => 'https://up.example.com/item/'.crc32($code),
                ], $codes),
            ]],
        ];
    }

    public function test_fetch_stock_rebuilds_dto_with_real_stock(): void
    {
        Http::fake([
            'https://up.example.com/shared/commodity/items' => Http::response([
                'code' => 200,
                'data' => [[
                    'id' => 1,
                    'name' => '分类A',
                    'children' => [
                        ['id' => 11, 'code' => 'C1', 'name' => '手动发货商品', 'price' => '100.00', 'delivery_way' => 1],
                        ['id' => 12, 'code' => 'C2', 'name' => '自动发货商品', 'price' => '50.00', 'delivery_way' => 0, 'stock' => 5],
                    ],
                ]],
            ]),
            'https://up.example.com/shared/commodity/stock' => Http::response([
                'code' => 200,
                'data' => ['stock' => 8],
            ]),
        ]);

        $source = $this->makeSource();
        $driver = new AcgFakaDriver($source);

        $result = $driver->listProducts(null, 1, fetchStock: true);

        $byCode = collect($result['items'])->keyBy('code');
        // 手动发货商品:items 无 stock → 补查得 8
        $this->assertSame(8, $byCode['C1']->stockQuantity);
        // 自动发货商品:items 自带 stock → 保持 5,不补查
        $this->assertSame(5, $byCode['C2']->stockQuantity);
    }

    public function test_missing_stock_route_falls_back_once_to_legacy_item_for_entire_catalog(): void
    {
        $codes = ['C1', 'C2', 'C3', 'C4', 'C5'];
        $stockRequests = 0;
        $itemRequests = [];
        Http::fake(function (Request $request) use ($codes, &$stockRequests, &$itemRequests) {
            if (str_ends_with($request->url(), '/items')) {
                return Http::response($this->itemsResponse($codes));
            }
            if (str_ends_with($request->url(), '/stock')) {
                $stockRequests++;

                return Http::response(
                    '<script>document.write(atob("NDA0IE5vdCBGb3VuZA=="))</script>',
                    200,
                    ['Content-Type' => 'text/html; charset=UTF-8'],
                );
            }
            if (str_ends_with($request->url(), '/item')) {
                $data = $request->data();
                $code = (string) ($data['code'] ?? '');
                $itemRequests[] = $code;
                $this->assertSame('aid', $data['app_id'] ?? null);
                $this->assertArrayNotHasKey('app_key', $data);
                $signing = ['app_id' => 'aid', 'code' => $code];
                ksort($signing);
                $this->assertSame(
                    md5(urldecode(http_build_query($signing)).'&key=key'),
                    $data['sign'] ?? null,
                );

                return Http::response([
                    'code' => 200,
                    'data' => ['stock' => (int) substr($code, 1) * 10],
                ]);
            }

            return Http::response('unexpected endpoint', 500);
        });
        $progress = [];
        $source = $this->makeSource(['schedule' => [
            'stock_concurrency' => 2,
            'stock_request_delay_ms' => 0,
        ]]);

        $result = (new AcgFakaDriver($source))->listProducts(
            null,
            1,
            fetchStock: true,
            progress: function (string $stage, int $current, int $total) use (&$progress): void {
                $progress[] = [$stage, $current, $total];
            },
        );

        $this->assertSame(1, $stockRequests, '确认旧版后不得让后续商品重复请求不存在的 stock 路由');
        $this->assertSame($codes, $itemRequests);
        $this->assertSame([0, 2, 4, 5], array_column($progress, 1));
        $this->assertSame([10, 20, 30, 40, 50], collect($result['items'])->pluck('stockQuantity')->all());
    }

    public function test_realtime_stock_falls_back_to_item_when_stock_route_returns_http_404(): void
    {
        Http::fake([
            'https://up.example.com/shared/commodity/stock' => Http::response('not found', 404),
            'https://up.example.com/shared/commodity/item' => Http::response([
                'code' => 200,
                'data' => ['stock' => 12],
            ]),
        ]);

        $stock = (new AcgFakaDriver($this->makeSource()))->getStock('C1');

        $this->assertSame(12, $stock);
        $this->assertSame([
            'https://up.example.com/shared/commodity/stock',
            'https://up.example.com/shared/commodity/item',
        ], Http::recorded()->map(fn (array $entry) => $entry[0]->url())->all());
    }

    public function test_stock_endpoint_block_is_not_silently_treated_as_unlimited_stock(): void
    {
        $stockRequests = 0;
        Http::fake([
            'https://up.example.com/shared/commodity/items' => Http::response([
                'code' => 200,
                'data' => [[
                    'id' => 1,
                    'name' => '分类A',
                    'children' => [
                        ['id' => 11, 'code' => 'C1', 'name' => '手动发货商品', 'price' => '100.00', 'delivery_way' => 1],
                    ],
                ]],
            ]),
            'https://up.example.com/shared/commodity/stock' => function () use (&$stockRequests) {
                $stockRequests++;

                return Http::response('IP forbidden', 403);
            },
        ]);

        try {
            (new AcgFakaDriver($this->makeSource()))->listProducts(null, 1, fetchStock: true);
            $this->fail('库存接口被屏蔽时必须让同步任务失败并显示原因');
        } catch (UpstreamRequestException $e) {
            $this->assertSame('UPSTREAM_FORBIDDEN', $e->errorCode);
            $this->assertStringContainsString('IP 白名单', $e->getMessage());
            $this->assertSame('https://up.example.com/shared/commodity/stock', $e->context['endpoint']);
            $this->assertSame(1, $stockRequests, '403 为不可重试配置错误，不应重复请求');
        }
    }

    public function test_stock_fetch_uses_configured_concurrency_and_reports_each_batch(): void
    {
        $codes = ['C1', 'C2', 'C3', 'C4', 'C5'];
        Http::fake(function (Request $request) use ($codes) {
            if (str_ends_with($request->url(), '/items')) {
                return Http::response($this->itemsResponse($codes));
            }

            return Http::response(['code' => 200, 'data' => ['stock' => 7]]);
        });
        $progress = [];
        $source = $this->makeSource(['schedule' => [
            'stock_concurrency' => 2,
            'stock_request_delay_ms' => 0,
        ]]);

        $result = (new AcgFakaDriver($source))->listProducts(
            null,
            1,
            fetchStock: true,
            progress: function (string $stage, int $current, int $total) use (&$progress): void {
                $progress[] = [$stage, $current, $total];
            },
        );

        $this->assertSame([0, 2, 4, 5], array_column($progress, 1));
        $this->assertSame([7, 7, 7, 7, 7], collect($result['items'])->pluck('stockQuantity')->all());
    }

    public function test_rate_limited_stock_request_retries_only_failed_product(): void
    {
        $attempts = [];
        Http::fake(function (Request $request) use (&$attempts) {
            if (str_ends_with($request->url(), '/items')) {
                return Http::response($this->itemsResponse(['C1', 'C2']));
            }
            $code = (string) ($request->data()['code'] ?? '');
            $attempts[$code] = ($attempts[$code] ?? 0) + 1;
            if ($code === 'C1' && $attempts[$code] === 1) {
                return Http::response('rate limited', 429);
            }

            return Http::response(['code' => 200, 'data' => ['stock' => $code === 'C1' ? 11 : 22]]);
        });
        $source = $this->makeSource(['schedule' => [
            'stock_concurrency' => 2,
            'stock_request_delay_ms' => 0,
        ]]);

        $result = (new AcgFakaDriver($source))->listProducts(null, 1, fetchStock: true);

        $this->assertSame(['C1' => 2, 'C2' => 1], $attempts);
        $this->assertSame(11, collect($result['items'])->firstWhere('code', 'C1')->stockQuantity);
        $this->assertSame(22, collect($result['items'])->firstWhere('code', 'C2')->stockQuantity);
    }

    public function test_transient_html_stock_response_recovers_on_retry(): void
    {
        $stockRequests = 0;
        Http::fake(function (Request $request) use (&$stockRequests) {
            if (str_ends_with($request->url(), '/items')) {
                return Http::response($this->itemsResponse(['C1']));
            }
            $stockRequests++;
            if ($stockRequests === 1) {
                return Http::response('<html>temporary gateway page</html>', 200, ['Content-Type' => 'text/html']);
            }

            return Http::response(['code' => 200, 'data' => ['stock' => 9]]);
        });

        $result = (new AcgFakaDriver($this->makeSource()))->listProducts(null, 1, fetchStock: true);

        $this->assertSame(2, $stockRequests);
        $this->assertSame(9, $result['items'][0]->stockQuantity);
    }

    public function test_persistent_html_stock_response_exhausts_bounded_retries_with_safe_diagnostics(): void
    {
        $stockRequests = 0;
        $html = '<html><title>Temporary gateway failure</title> token=secret-value</html>';
        Http::fake(function (Request $request) use (&$stockRequests, $html) {
            if (str_ends_with($request->url(), '/items')) {
                return Http::response($this->itemsResponse(['C1']));
            }
            $stockRequests++;

            return Http::response($html, 200, [
                'Content-Type' => 'text/html; charset=UTF-8',
                'CF-Ray' => 'example-ray-SOF',
                'Location' => 'https://login.example.com/challenge?token=location-secret',
            ]);
        });

        try {
            (new AcgFakaDriver($this->makeSource()))->listProducts(null, 1, fetchStock: true);
            $this->fail('持续伪 200 HTML 必须在有界重试耗尽后失败');
        } catch (UpstreamRequestException $e) {
            $this->assertSame('UPSTREAM_INVALID_RESPONSE', $e->errorCode);
            $this->assertTrue($e->retryable);
            $this->assertSame(3, $stockRequests);
            $this->assertSame(3, $e->context['attempt']);
            $this->assertSame(3, $e->context['max_attempts']);
            $this->assertSame(200, $e->context['http_status']);
            $this->assertSame('text/html; charset=UTF-8', $e->context['content_type']);
            $this->assertSame('example-ray-SOF', $e->context['cf_ray']);
            $this->assertSame('https://login.example.com/challenge', $e->context['location']);
            $this->assertSame(strlen($html), $e->context['body_bytes']);
            $this->assertSame(hash('sha256', $html), $e->context['body_sha256']);
            $this->assertStringContainsString('Temporary gateway failure', $e->context['response_preview']);
            $this->assertStringNotContainsString('secret-value', $e->context['response_preview']);
            $this->assertStringNotContainsString('location-secret', json_encode($e->context));
            $this->assertFalse(Http::recorded()->contains(
                fn (array $entry) => str_ends_with($entry[0]->url(), '/item'),
            ), '普通网关 HTML 不得误降级到旧版 item 接口');
        }
    }

    public function test_stock_throttle_configuration_must_fit_job_budget(): void
    {
        $codes = array_map(fn (int $i) => "C{$i}", range(1, 62));
        Http::fake([
            'https://up.example.com/shared/commodity/items' => Http::response($this->itemsResponse($codes)),
        ]);
        $source = $this->makeSource(['schedule' => [
            'stock_concurrency' => 1,
            'stock_request_delay_ms' => 10_000,
        ]]);

        try {
            (new AcgFakaDriver($source))->listProducts(null, 1, fetchStock: true);
            $this->fail('主动限速等待超过 Job 预算时必须在发出库存请求前失败');
        } catch (UpstreamRequestException $e) {
            $this->assertSame('STOCK_SYNC_BUDGET_EXCEEDED', $e->errorCode);
            $this->assertSame(610, $e->context['estimated_throttle_seconds']);
        }

        $this->assertCount(
            1,
            Http::recorded(),
            json_encode(Http::recorded()->map(fn (array $entry) => $entry[0]->url())->all()),
        );
    }
}
