<?php

namespace Tests\Feature;

use App\Jobs\SyncSupplySourceProducts;
use App\Models\SupplySource;
use App\Models\SupplySyncTask;
use App\Support\StorefrontConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class SupplyScheduledSyncCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        StorefrontConfig::setMany(['supply_enabled' => true]);
        Queue::fake();
    }

    private function makeSource(array $settings = []): SupplySource
    {
        return SupplySource::create([
            'name' => 'S', 'driver' => 'dujiao_next', 'base_url' => 'https://x.com',
            'credentials' => [], 'status' => 'active', 'settings' => $settings,
        ]);
    }

    private function scheduleSource(array $overrides = []): SupplySource
    {
        $settings = array_merge([
            'schedule' => [
                'enabled' => true,
                'request_delay' => 0,
                'collect' => ['enabled' => true, 'mode' => 'incremental', 'interval' => 5, 'windows' => []],
                'price' => ['enabled' => true, 'interval' => 5, 'windows' => []],
                'status' => ['enabled' => true, 'interval' => 5, 'windows' => []],
            ],
        ], $overrides);

        return $this->makeSource($settings);
    }

    public function test_dispatches_collect_when_due_and_marks_last_collect_at(): void
    {
        $source = $this->scheduleSource();
        $this->assertNull($source->last_collect_at);

        $this->artisan('supply:scheduled-sync')->assertSuccessful();

        Queue::assertPushed(SyncSupplySourceProducts::class, function (SyncSupplySourceProducts $job) use ($source) {
            return $job->sourceId === $source->id
                && $job->scope === 'collect'
                && $job->mode === 'incremental';
        });
        $this->assertNotNull($source->fresh()->last_collect_at);
        // 任务记录带 scope
        $task = SupplySyncTask::where('supply_source_id', $source->id)->first();
        $this->assertSame('collect', $task->scope);
    }

    public function test_does_not_dispatch_again_when_interval_not_elapsed(): void
    {
        // 只启用采集:标记上次采集后,间隔未到 → 不再派发
        $source = $this->scheduleSource([
            'schedule' => [
                'enabled' => true,
                'request_delay' => 0,
                'collect' => ['enabled' => true, 'mode' => 'incremental', 'interval' => 5, 'windows' => []],
                'price' => ['enabled' => false, 'interval' => 5, 'windows' => []],
                'status' => ['enabled' => false, 'interval' => 5, 'windows' => []],
            ],
        ]);
        $source->update(['last_collect_at' => now()]);

        $this->artisan('supply:scheduled-sync')->assertSuccessful();

        Queue::assertNotPushed(SyncSupplySourceProducts::class);
    }

    public function test_dispatches_price_when_collect_not_due_but_price_due(): void
    {
        // 采集不启用(price/status 启用)→ 应派发 price
        $source = $this->scheduleSource([
            'schedule' => [
                'enabled' => true,
                'request_delay' => 0,
                'collect' => ['enabled' => false, 'interval' => 5, 'windows' => []],
                'price' => ['enabled' => true, 'interval' => 5, 'windows' => []],
                'status' => ['enabled' => true, 'interval' => 5, 'windows' => []],
            ],
        ]);

        $this->artisan('supply:scheduled-sync')->assertSuccessful();

        Queue::assertPushed(SyncSupplySourceProducts::class, fn (SyncSupplySourceProducts $job) => $job->sourceId === $source->id && $job->scope === 'price');
        // 同一周期只派发一个任务:price 已派发,status 等下一周期
        $this->assertSame(1, Queue::pushed(SyncSupplySourceProducts::class)->count());
        $this->assertNotNull($source->fresh()->last_price_sync_at);
    }

    public function test_collect_takes_priority_over_price_and_status(): void
    {
        $source = $this->scheduleSource();

        $this->artisan('supply:scheduled-sync')->assertSuccessful();

        $pushed = Queue::pushed(SyncSupplySourceProducts::class);
        $this->assertSame(1, $pushed->count());
        $this->assertSame('collect', $pushed->first()->scope);
    }

    public function test_time_window_outside_skips_dispatch(): void
    {
        $source = $this->scheduleSource([
            'schedule' => [
                'enabled' => true,
                'request_delay' => 0,
                'collect' => ['enabled' => true, 'mode' => 'incremental', 'interval' => 1, 'windows' => [['start' => '00:00', 'end' => '00:01']]],
                'price' => ['enabled' => false, 'interval' => 1, 'windows' => []],
                'status' => ['enabled' => false, 'interval' => 1, 'windows' => []],
            ],
        ]);

        $this->artisan('supply:scheduled-sync')->assertSuccessful();

        Queue::assertNotPushed(SyncSupplySourceProducts::class);
    }

    public function test_legacy_auto_sync_source_still_dispatches_collect(): void
    {
        $source = $this->makeSource(['auto_sync' => true]);

        $this->artisan('supply:scheduled-sync')->assertSuccessful();

        Queue::assertPushed(SyncSupplySourceProducts::class, function (SyncSupplySourceProducts $job) use ($source) {
            return $job->sourceId === $source->id && $job->scope === 'collect';
        });
    }

    public function test_skips_disabled_and_schedule_disabled_sources(): void
    {
        // 停用货源:即使 auto_sync=true 也不调度
        SupplySource::create([
            'name' => 'Disabled', 'driver' => 'dujiao_next', 'base_url' => 'https://x.com',
            'credentials' => [], 'status' => 'disabled', 'settings' => ['auto_sync' => true],
        ]);
        // 总开关关闭的货源
        $this->scheduleSource(['schedule' => ['enabled' => false, 'request_delay' => 0]]);

        $this->artisan('supply:scheduled-sync')->assertSuccessful();

        Queue::assertNotPushed(SyncSupplySourceProducts::class);
    }

    public function test_skips_when_running_task_exists(): void
    {
        $source = $this->scheduleSource();
        SupplySyncTask::create([
            'supply_source_id' => $source->id,
            'mode' => 'incremental',
            'scope' => 'collect',
            'status' => SupplySyncTask::STATUS_RUNNING,
        ]);

        $this->artisan('supply:scheduled-sync')->assertSuccessful();

        Queue::assertNotPushed(SyncSupplySourceProducts::class);
    }

    public function test_skips_all_when_supply_disabled(): void
    {
        StorefrontConfig::setMany(['supply_enabled' => false]);
        $this->scheduleSource();

        $this->artisan('supply:scheduled-sync')->assertSuccessful();

        Queue::assertNotPushed(SyncSupplySourceProducts::class);
    }
}
