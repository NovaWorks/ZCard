<?php

namespace Tests\Feature;

use App\Jobs\SyncSupplySourceProducts;
use App\Models\Merchant;
use App\Models\SupplySource;
use App\Models\SupplySyncTask;
use App\Models\User;
use App\Supply\Contracts\SupplyDriver;
use App\Supply\Dto\UpstreamProduct;
use App\Supply\SupplyManager;
use App\Supply\SupplySyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * 货源同步任务(异步化):派发/状态流转/进度/取消/防重。
 */
class SupplySyncTaskTest extends TestCase
{
    use RefreshDatabase;

    private function makeSource(array $settings = []): SupplySource
    {
        $user = User::factory()->create();
        Merchant::query()->firstOrCreate(
            ['id' => 1],
            ['name' => '主站', 'slug' => 'main-'.uniqid(), 'user_id' => $user->id, 'settings' => []],
        );

        return SupplySource::create([
            'name' => 'S', 'driver' => 'acg_faka', 'base_url' => 'https://x.com',
            'credentials' => [], 'status' => 'active', 'settings' => $settings,
        ]);
    }

    private function adminHeaders(): array
    {
        foreach (['super_admin', 'merchant', 'user'] as $r) {
            Role::firstOrCreate(['name' => $r]);
        }
        $user = User::factory()->create();
        $user->assignRole('super_admin');

        return ['Authorization' => 'Bearer '.$user->createToken('test')->plainTextToken];
    }

    public function test_sync_dispatches_async_task_and_rejects_duplicate(): void
    {
        Queue::fake();
        $source = $this->makeSource();

        $resp = $this->withHeaders($this->adminHeaders())
            ->postJson("/api/admin/supply-sources/{$source->id}/sync", ['mode' => 'incremental']);
        $resp->assertOk();
        $task = $resp->json('task');
        $this->assertSame('queued', $task['status']);

        Queue::assertPushed(SyncSupplySourceProducts::class, fn ($job) => $job->taskId === $task['id']);

        // 防重:进行中任务时再同步 → 409
        $dup = $this->withHeaders($this->adminHeaders())
            ->postJson("/api/admin/supply-sources/{$source->id}/sync");
        $dup->assertStatus(409);
    }

    public function test_task_runs_to_success_with_progress(): void
    {
        $source = $this->makeSource();
        $task = SupplySyncTask::create([
            'supply_source_id' => $source->id,
            'mode' => 'incremental',
            'status' => SupplySyncTask::STATUS_QUEUED,
        ]);

        // mock 驱动:单页返回 2 个商品
        $driver = $this->createMock(SupplyDriver::class);
        $driver->method('listProducts')->willReturn([
            'items' => [
                new UpstreamProduct(code: 'A', name: 'A', price: 100, factoryPrice: 100),
                new UpstreamProduct(code: 'B', name: 'B', price: 200, factoryPrice: 200),
            ],
            'total' => 2, 'page' => 1, 'has_more' => false,
        ]);
        $manager = $this->createMock(SupplyManager::class);
        $manager->method('driver')->willReturn($driver);

        $job = new SyncSupplySourceProducts($source->id, 'incremental', $task->id);
        $job->handle($manager, app(SupplySyncService::class));

        $task->refresh();
        $this->assertSame(SupplySyncTask::STATUS_SUCCESS, $task->status);
        $this->assertSame(2, (int) $task->processed_products);
        $this->assertSame(2, (int) $task->created_count);
        $this->assertNotNull($task->finished_at);
    }

    public function test_cancelled_task_stops_early(): void
    {
        $source = $this->makeSource();
        $task = SupplySyncTask::create([
            'supply_source_id' => $source->id,
            'mode' => 'incremental',
            'status' => SupplySyncTask::STATUS_QUEUED,
        ]);

        // 第一次调用后把任务置 cancelled → Job 第二页应中止
        $driver = $this->createMock(SupplyDriver::class);
        $driver->method('listProducts')->willReturnOnConsecutiveCalls(
            ['items' => [new UpstreamProduct(code: 'A', name: 'A', price: 100, factoryPrice: 100)],
                'total' => 1, 'page' => 1, 'has_more' => true],
            ['items' => [], 'total' => 0, 'page' => 2, 'has_more' => false],
        );
        $manager = $this->createMock(SupplyManager::class);
        $manager->method('driver')->willReturn($driver);

        $job = new SyncSupplySourceProducts($source->id, 'incremental', $task->id);
        // 第一页执行前先标记取消 → 直接中止为 cancelled
        $task->update(['status' => SupplySyncTask::STATUS_CANCELLED]);
        $job->handle($manager, app(SupplySyncService::class));

        $task->refresh();
        $this->assertSame(SupplySyncTask::STATUS_CANCELLED, $task->status);
    }

    public function test_sync_cancel_api_marks_task_cancelled(): void
    {
        $source = $this->makeSource();
        SupplySyncTask::create([
            'supply_source_id' => $source->id,
            'mode' => 'incremental',
            'status' => SupplySyncTask::STATUS_RUNNING,
        ]);

        $resp = $this->withHeaders($this->adminHeaders())
            ->postJson("/api/admin/supply-sources/{$source->id}/sync-cancel");
        $resp->assertOk();
        $this->assertSame('cancelled', $resp->json('task.status'));

        // 无进行中任务 → 404
        $again = $this->withHeaders($this->adminHeaders())
            ->postJson("/api/admin/supply-sources/{$source->id}/sync-cancel");
        $again->assertStatus(404);
    }

    public function test_all_tasks_endpoint_includes_source_name(): void
    {
        $source = $this->makeSource();
        SupplySyncTask::create(['supply_source_id' => $source->id, 'mode' => 'incremental', 'status' => 'running']);
        SupplySyncTask::create(['supply_source_id' => $source->id, 'mode' => 'incremental', 'status' => 'success']);

        $resp = $this->withHeaders($this->adminHeaders())
            ->getJson('/api/admin/supply-sources/sync-tasks');
        $resp->assertOk();
        $tasks = $resp->json('tasks');
        $this->assertCount(2, $tasks);
        $this->assertSame('S', $tasks[0]['source_name']);
    }

    public function test_queue_probe_and_status(): void
    {
        // 探针 Job 派发(sync 队列下立即执行并写心跳)
        $this->withHeaders($this->adminHeaders())
            ->postJson('/api/admin/supply-sources/sync-queue-probe')
            ->assertOk();

        $resp = $this->withHeaders($this->adminHeaders())
            ->getJson('/api/admin/supply-sources/sync-queue-status');
        $resp->assertOk();
        $this->assertNotNull($resp->json('heartbeat_at'), '探针执行后应有心跳');
        $this->assertTrue($resp->json('healthy'));
    }

    public function test_sync_tasks_list_returns_latest_first(): void
    {
        $source = $this->makeSource();
        SupplySyncTask::create(['supply_source_id' => $source->id, 'mode' => 'incremental', 'status' => 'success']);
        SupplySyncTask::create(['supply_source_id' => $source->id, 'mode' => 'incremental', 'status' => 'failed']);

        $resp = $this->withHeaders($this->adminHeaders())
            ->getJson("/api/admin/supply-sources/{$source->id}/sync-tasks");
        $resp->assertOk();
        $tasks = $resp->json('tasks');
        $this->assertCount(2, $tasks);
        $this->assertSame('failed', $tasks[0]['status']); // 最新优先
    }
}
