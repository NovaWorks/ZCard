<?php

namespace Tests\Feature;

use App\Models\SupplySource;
use App\Supply\Drivers\AcgFakaDriver;
use App\Supply\Exceptions\UpstreamRequestException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * acg-faka 驱动库存补查(fetchStock=true):
 * readonly DTO 重新构造(兼容 PHP 8.3,不用 clone 赋值),库存准确写入。
 */
class AcgFakaStockFetchTest extends TestCase
{
    use RefreshDatabase;

    private function makeSource(): SupplySource
    {
        return SupplySource::create([
            'name' => 'acg-faka 上游', 'driver' => 'acg_faka',
            'base_url' => 'https://up.example.com',
            'credentials' => ['app_id' => 'aid', 'app_key' => 'key'],
            'status' => 'active', 'settings' => [],
        ]);
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

    public function test_stock_endpoint_block_is_not_silently_treated_as_unlimited_stock(): void
    {
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
            'https://up.example.com/shared/commodity/stock' => Http::response('IP forbidden', 403),
        ]);

        try {
            (new AcgFakaDriver($this->makeSource()))->listProducts(null, 1, fetchStock: true);
            $this->fail('库存接口被屏蔽时必须让同步任务失败并显示原因');
        } catch (UpstreamRequestException $e) {
            $this->assertSame('UPSTREAM_FORBIDDEN', $e->errorCode);
            $this->assertStringContainsString('IP 白名单', $e->getMessage());
            $this->assertSame('https://up.example.com/shared/commodity/stock', $e->context['endpoint']);
        }
    }

    public function test_stock_html_response_preserves_safe_http_diagnostics(): void
    {
        $html = '<html><title>404 Not Found</title> token=secret-value</html>';
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
            'https://up.example.com/shared/commodity/stock' => Http::response($html, 200, [
                'Content-Type' => 'text/html; charset=UTF-8',
                'CF-Ray' => 'example-ray-SOF',
                'Location' => 'https://login.example.com/challenge?token=location-secret',
            ]),
        ]);

        try {
            (new AcgFakaDriver($this->makeSource()))->listProducts(null, 1, fetchStock: true);
            $this->fail('库存接口返回伪 200 HTML 时必须让同步任务失败');
        } catch (UpstreamRequestException $e) {
            $this->assertSame('UPSTREAM_INVALID_RESPONSE', $e->errorCode);
            $this->assertSame(200, $e->context['http_status']);
            $this->assertSame('text/html; charset=UTF-8', $e->context['content_type']);
            $this->assertSame('example-ray-SOF', $e->context['cf_ray']);
            $this->assertSame('https://login.example.com/challenge', $e->context['location']);
            $this->assertSame(strlen($html), $e->context['body_bytes']);
            $this->assertSame(hash('sha256', $html), $e->context['body_sha256']);
            $this->assertStringContainsString('404 Not Found', $e->context['response_preview']);
            $this->assertStringNotContainsString('secret-value', $e->context['response_preview']);
            $this->assertStringNotContainsString('location-secret', json_encode($e->context));
        }
    }
}
