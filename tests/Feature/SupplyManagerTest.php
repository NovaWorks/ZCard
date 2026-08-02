<?php

namespace Tests\Feature;

use App\Models\SupplySource;
use App\Supply\Drivers\AcgFakaDriver;
use App\Supply\Drivers\DujiaoNextDriver;
use App\Supply\Drivers\ZCardDriver;
use App\Supply\SupplyManager;
use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;

class SupplyManagerTest extends TestCase
{
    use RefreshDatabase;

    public function test_driver_returns_correct_instance(): void
    {
        $dujiao = SupplySource::create([
            'name' => 'd', 'driver' => SupplySource::DRIVER_DUJIAO_NEXT,
            'base_url' => 'https://a.com', 'credentials' => [], 'status' => 'active',
        ]);
        $acg = SupplySource::create([
            'name' => 'a', 'driver' => SupplySource::DRIVER_ACG_FAKA,
            'base_url' => 'https://b.com', 'credentials' => [], 'status' => 'active',
        ]);
        $zcard = SupplySource::create([
            'name' => 'z', 'driver' => SupplySource::DRIVER_ZCARD,
            'base_url' => 'https://c.com', 'credentials' => [], 'status' => 'active',
        ]);

        $this->assertInstanceOf(DujiaoNextDriver::class, app(SupplyManager::class)->driver($dujiao));
        $this->assertInstanceOf(AcgFakaDriver::class, app(SupplyManager::class)->driver($acg));
        $this->assertInstanceOf(ZCardDriver::class, app(SupplyManager::class)->driver($zcard));
    }

    public function test_unknown_driver_throws(): void
    {
        $source = SupplySource::create([
            'name' => 'x', 'driver' => 'nonexistent',
            'base_url' => 'https://x.com', 'credentials' => [], 'status' => 'active',
        ]);

        $this->expectException(InvalidArgumentException::class);
        app(SupplyManager::class)->driver($source);
    }

    public function test_all_drivers_meta_returns_schema(): void
    {
        $meta = SupplyManager::allDriversMeta();

        $this->assertCount(3, $meta);
        $drivers = array_column($meta, 'driver');
        $this->assertContains(SupplySource::DRIVER_DUJIAO_NEXT, $drivers);
        $this->assertContains(SupplySource::DRIVER_ACG_FAKA, $drivers);
        $this->assertContains(SupplySource::DRIVER_ZCARD, $drivers);

        // 每个都有 config_schema
        foreach ($meta as $m) {
            $this->assertArrayHasKey('config_schema', $m);
            $this->assertArrayHasKey('base_url', $m['config_schema']);
        }
    }
}
