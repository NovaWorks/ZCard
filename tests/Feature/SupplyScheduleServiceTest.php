<?php

namespace Tests\Feature;

use App\Models\SupplySource;
use App\Supply\SupplyScheduleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SupplyScheduleServiceTest extends TestCase
{
    use RefreshDatabase;

    private function makeSource(array $settings = []): SupplySource
    {
        return SupplySource::create([
            'name' => 'S', 'driver' => 'dujiao_next', 'base_url' => 'https://x.com',
            'credentials' => [], 'status' => 'active', 'settings' => $settings,
        ]);
    }

    public function test_legacy_auto_sync_is_treated_as_enabled_hourly_collect(): void
    {
        $source = $this->makeSource(['auto_sync' => true]);
        $service = app(SupplyScheduleService::class);

        $this->assertTrue($service->isEnabled($source));

        $collect = $service->config($source, 'collect');
        $this->assertTrue($collect['enabled']);
        $this->assertSame('incremental', $collect['mode']);
        $this->assertSame(60, $collect['interval']); // 兼容旧版每小时
        $this->assertSame([], $collect['windows']);

        // 旧版只开采集,价格/上下架默认关
        $this->assertFalse($service->config($source, 'price')['enabled']);
        $this->assertFalse($service->config($source, 'status')['enabled']);
    }

    public function test_legacy_auto_sync_disabled_means_disabled(): void
    {
        $source = $this->makeSource(['auto_sync' => false]);
        $service = app(SupplyScheduleService::class);

        $this->assertFalse($service->isEnabled($source));
    }

    public function test_schedule_enabled_switch(): void
    {
        $source = $this->makeSource([
            'schedule' => ['enabled' => false, 'collect' => ['enabled' => true]],
        ]);
        $service = app(SupplyScheduleService::class);

        $this->assertFalse($service->isEnabled($source));
    }

    public function test_config_reads_interval_and_defaults(): void
    {
        $source = $this->makeSource([
            'schedule' => [
                'enabled' => true,
                'collect' => ['enabled' => true, 'mode' => 'full', 'interval' => 720, 'windows' => [['start' => '02:00', 'end' => '06:00']]],
                'price' => ['enabled' => true, 'interval' => 15],
            ],
        ]);
        $service = app(SupplyScheduleService::class);

        $collect = $service->config($source, 'collect');
        $this->assertTrue($collect['enabled']);
        $this->assertSame('full', $collect['mode']);
        $this->assertSame(720, $collect['interval']);
        $this->assertSame([['start' => '02:00', 'end' => '06:00']], $collect['windows']);

        $price = $service->config($source, 'price');
        $this->assertTrue($price['enabled']);
        $this->assertSame(15, $price['interval']);

        // 未配置的上下架默认关闭
        $this->assertFalse($service->config($source, 'status')['enabled']);
    }

    public function test_collect_mode_defaults_to_incremental(): void
    {
        $source = $this->makeSource([
            'schedule' => ['enabled' => true, 'collect' => ['enabled' => true, 'interval' => 60]],
        ]);
        $service = app(SupplyScheduleService::class);

        $this->assertSame('incremental', $service->config($source, 'collect')['mode']);
    }

    public function test_is_due_never_run_is_due(): void
    {
        $source = $this->makeSource([
            'schedule' => ['enabled' => true, 'collect' => ['enabled' => true, 'interval' => 60]],
        ]);
        $service = app(SupplyScheduleService::class);

        $this->assertTrue($service->isDue($source, 'collect', $service->config($source, 'collect')));
    }

    public function test_is_due_skips_when_interval_not_elapsed(): void
    {
        $source = $this->makeSource([
            'schedule' => ['enabled' => true, 'price' => ['enabled' => true, 'interval' => 30]],
        ]);
        $source->update(['last_price_sync_at' => now()->subMinutes(10)]);
        $service = app(SupplyScheduleService::class);

        $this->assertFalse($service->isDue($source, 'price', $service->config($source, 'price')));
    }

    public function test_is_due_true_after_interval(): void
    {
        $source = $this->makeSource([
            'schedule' => ['enabled' => true, 'status' => ['enabled' => true, 'interval' => 60]],
        ]);
        $source->update(['last_status_sync_at' => now()->subMinutes(61)]);
        $service = app(SupplyScheduleService::class);

        $this->assertTrue($service->isDue($source, 'status', $service->config($source, 'status')));
    }

    public function test_is_due_respects_disabled_scope(): void
    {
        $source = $this->makeSource([
            'schedule' => ['enabled' => true, 'price' => ['enabled' => false, 'interval' => 1]],
        ]);
        $service = app(SupplyScheduleService::class);

        $this->assertFalse($service->isDue($source, 'price', $service->config($source, 'price')));
    }

    public function test_in_window_empty_means_all_day(): void
    {
        $service = app(SupplyScheduleService::class);

        $this->assertTrue($service->inWindow([]));
    }

    public function test_in_window_inside_and_outside(): void
    {
        $service = app(SupplyScheduleService::class);
        $now = now()->format('H:i');

        $this->assertTrue($service->inWindow([['start' => '00:00', 'end' => '23:59']]));
        $this->assertFalse($service->inWindow([['start' => '00:00', 'end' => '00:01']]));
        // 跨天窗口(23:00-01:00):除非当前正好在 23:00-24:00 或 00:00-01:00,否则为 false
        $cross = $service->inWindow([['start' => '23:00', 'end' => '01:00']]);
        $hour = (int) now()->format('H');
        $this->assertSame($hour >= 23 || $hour <= 1, $cross);
    }
}
