<?php

namespace Tests\Feature;

use App\Models\SupplySource;
use App\Supply\Drivers\AcgFakaDriver;
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
}
