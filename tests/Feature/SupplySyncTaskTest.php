<?php

namespace Tests\Feature;

use App\Jobs\SyncSupplySourceProducts;
use App\Models\Merchant;
use App\Models\Product;
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

    public function test_full_sync_soft_deletes_explicitly_inactive_and_missing_products(): void
    {
        $source = $this->makeSource();
        $sync = app(SupplySyncService::class);
        $keep = $sync->upsertProduct($source, new UpstreamProduct(code: 'KEEP', name: '保留', price: 100, factoryPrice: 100));
        $inactive = $sync->upsertProduct($source, new UpstreamProduct(code: 'INACTIVE', name: '失效', price: 100, factoryPrice: 100));
        $missing = $sync->upsertProduct($source, new UpstreamProduct(code: 'MISSING', name: '已删除', price: 100, factoryPrice: 100));
        $task = SupplySyncTask::create([
            'supply_source_id' => $source->id,
            'mode' => 'full',
            'status' => SupplySyncTask::STATUS_QUEUED,
        ]);

        $driver = $this->createMock(SupplyDriver::class);
        $driver->method('listProducts')->willReturn([
            'items' => [
                new UpstreamProduct(code: 'KEEP', name: '保留', price: 100, factoryPrice: 100),
                new UpstreamProduct(code: 'INACTIVE', name: '失效', price: 100, factoryPrice: 100, isActive: false),
            ],
            'total' => 2, 'page' => 1, 'has_more' => false,
        ]);
        $manager = $this->createMock(SupplyManager::class);
        $manager->method('driver')->willReturn($driver);

        (new SyncSupplySourceProducts($source->id, 'full', $task->id))->handle($manager, $sync);

        $this->assertNotSoftDeleted('products', ['id' => $keep->id]);
        $this->assertSoftDeleted('products', ['id' => $inactive->id]);
        $this->assertSoftDeleted('products', ['id' => $missing->id]);
        $this->assertDatabaseHas('supply_sync_tasks', [
            'id' => $task->id,
            'status' => SupplySyncTask::STATUS_SUCCESS,
            'deleted_count' => 2,
        ]);
    }

    public function test_incremental_sync_does_not_delete_products_missing_from_current_response(): void
    {
        $source = $this->makeSource();
        $sync = app(SupplySyncService::class);
        $existing = $sync->upsertProduct($source, new UpstreamProduct(code: 'UNCHANGED', name: '未变化', price: 100, factoryPrice: 100));
        $task = SupplySyncTask::create([
            'supply_source_id' => $source->id,
            'mode' => 'incremental',
            'status' => SupplySyncTask::STATUS_QUEUED,
        ]);

        $driver = $this->createMock(SupplyDriver::class);
        $driver->method('supportsIncrementalProductSync')->willReturn(true);
        $driver->method('listProducts')->willReturn([
            'items' => [new UpstreamProduct(code: 'CHANGED', name: '已变化', price: 200, factoryPrice: 200)],
            'total' => 1, 'page' => 1, 'has_more' => false,
        ]);
        $manager = $this->createMock(SupplyManager::class);
        $manager->method('driver')->willReturn($driver);

        (new SyncSupplySourceProducts($source->id, 'incremental', $task->id))->handle($manager, $sync);

        $this->assertNotSoftDeleted('products', ['id' => $existing->id]);
        $this->assertSame(0, (int) $task->fresh()->deleted_count);
    }

    public function test_incremental_mode_reconciles_missing_products_for_full_snapshot_driver(): void
    {
        $source = $this->makeSource();
        $sync = app(SupplySyncService::class);
        $existing = $sync->upsertProduct($source, new UpstreamProduct(code: 'REMOVED', name: '上游已删', price: 100, factoryPrice: 100));
        $task = SupplySyncTask::create([
            'supply_source_id' => $source->id,
            'mode' => 'incremental',
            'status' => SupplySyncTask::STATUS_QUEUED,
        ]);

        $driver = $this->createMock(SupplyDriver::class);
        $driver->method('supportsIncrementalProductSync')->willReturn(false);
        $driver->method('listProducts')->willReturn([
            'items' => [], 'total' => 0, 'page' => 1, 'has_more' => false,
        ]);
        $manager = $this->createMock(SupplyManager::class);
        $manager->method('driver')->willReturn($driver);

        (new SyncSupplySourceProducts($source->id, 'incremental', $task->id))->handle($manager, $sync);

        $this->assertSoftDeleted('products', ['id' => $existing->id]);
        $this->assertSame(1, (int) $task->fresh()->deleted_count);
    }

    public function test_incomplete_full_sync_fails_without_deleting_missing_products(): void
    {
        $source = $this->makeSource();
        $sync = app(SupplySyncService::class);
        $existing = $sync->upsertProduct($source, new UpstreamProduct(code: 'MISSING', name: '不能误删', price: 100, factoryPrice: 100));
        $task = SupplySyncTask::create([
            'supply_source_id' => $source->id,
            'mode' => 'full',
            'status' => SupplySyncTask::STATUS_QUEUED,
        ]);

        $driver = $this->createMock(SupplyDriver::class);
        $driver->method('listProducts')->willReturn([
            'items' => [new UpstreamProduct(code: 'ONLY_ONE', name: '只返回一个', price: 100, factoryPrice: 100)],
            'total' => 2, 'page' => 1, 'has_more' => false,
        ]);
        $manager = $this->createMock(SupplyManager::class);
        $manager->method('driver')->willReturn($driver);

        try {
            (new SyncSupplySourceProducts($source->id, 'full', $task->id))->handle($manager, $sync);
            $this->fail('分页不完整时必须终止任务');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('分页不完整', $e->getMessage());
        }

        $this->assertNotSoftDeleted('products', ['id' => $existing->id]);
        $this->assertSame(SupplySyncTask::STATUS_FAILED, $task->fresh()->status);
        $this->assertSame(0, (int) $task->fresh()->deleted_count);
        $this->assertSame(1, Product::where('upstream_product_code', 'ONLY_ONE')->count());
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

    public function test_updating_markup_percent_dispatches_full_sync(): void
    {
        Queue::fake();
        $source = $this->makeSource(['default_pricing_mode' => 'percent', 'default_markup_percent' => 150]);
        $product = app(SupplySyncService::class)->upsertProduct(
            $source,
            new UpstreamProduct(code: 'REPRICE', name: '待重算商品', price: 100, factoryPrice: 80),
        );
        $this->assertSame(250, (int) $product->price);

        // 编辑货源:加价比例 150 → 600
        $resp = $this->withHeaders($this->adminHeaders())
            ->putJson("/api/admin/supply-sources/{$source->id}", [
                'name' => $source->name,
                'driver' => $source->driver,
                'base_url' => $source->base_url,
                'settings' => [
                    'default_pricing_mode' => 'percent',
                    'default_markup_percent' => 600,
                ],
            ]);
        $resp->assertOk();

        // 自动派发全量同步任务(价格重算)
        Queue::assertPushed(SyncSupplySourceProducts::class, fn ($job) => $job->mode === 'full');
        $this->assertDatabaseHas('supply_sync_tasks', [
            'supply_source_id' => $source->id,
            'mode' => 'full',
            'status' => 'queued',
        ]);

        // 执行已派发任务:不仅验证“有任务”，还验证商品售价与任务统计真正更新。
        $driver = $this->createMock(SupplyDriver::class);
        $driver->method('listProducts')->willReturn([
            'items' => [new UpstreamProduct(code: 'REPRICE', name: '待重算商品', price: 100, factoryPrice: 80)],
            'total' => 1, 'page' => 1, 'has_more' => false,
        ]);
        $manager = $this->createMock(SupplyManager::class);
        $manager->method('driver')->willReturn($driver);
        /** @var SyncSupplySourceProducts $job */
        $job = Queue::pushed(SyncSupplySourceProducts::class)->first();
        $job->handle($manager, app(SupplySyncService::class));

        $this->assertSame(700, (int) $product->fresh()->price, '100 分上游售价 + 600% 应重算为 700 分');
        $this->assertDatabaseHas('supply_sync_tasks', [
            'id' => $job->taskId,
            'status' => SupplySyncTask::STATUS_SUCCESS,
            'price_updated_count' => 1,
        ]);
    }

    public function test_normal_sync_reports_products_skipped_by_manual_price_protection(): void
    {
        $source = $this->makeSource(['default_pricing_mode' => 'percent', 'default_markup_percent' => 10]);
        $product = app(SupplySyncService::class)->upsertProduct(
            $source,
            new UpstreamProduct(code: 'MANUAL', name: '手动价商品', price: 100, factoryPrice: 80),
        );
        $product->update(['price' => 999, 'price_manual' => true]);
        $task = SupplySyncTask::create([
            'supply_source_id' => $source->id,
            'mode' => 'full',
            'status' => SupplySyncTask::STATUS_QUEUED,
        ]);

        $driver = $this->createMock(SupplyDriver::class);
        $driver->method('listProducts')->willReturn([
            'items' => [new UpstreamProduct(code: 'MANUAL', name: '手动价商品', price: 100, factoryPrice: 80)],
            'total' => 1, 'page' => 1, 'has_more' => false,
        ]);
        $manager = $this->createMock(SupplyManager::class);
        $manager->method('driver')->willReturn($driver);

        (new SyncSupplySourceProducts($source->id, 'full', $task->id))
            ->handle($manager, app(SupplySyncService::class));

        $this->assertSame(999, (int) $product->fresh()->price);
        $this->assertTrue((bool) $product->fresh()->price_manual);
        $this->assertDatabaseHas('supply_sync_tasks', [
            'id' => $task->id,
            'price_updated_count' => 0,
            'manual_price_skipped_count' => 1,
        ]);
    }

    public function test_force_reprice_overrides_manual_price_and_restores_auto_pricing(): void
    {
        Queue::fake();
        $source = $this->makeSource(['default_pricing_mode' => 'percent', 'default_markup_percent' => 10]);
        $product = app(SupplySyncService::class)->upsertProduct(
            $source,
            new UpstreamProduct(code: 'FORCE', name: '强制重算商品', price: 100, factoryPrice: 80),
        );
        $product->update(['price' => 999, 'price_manual' => true]);

        $response = $this->withHeaders($this->adminHeaders())
            ->postJson("/api/admin/supply-sources/{$source->id}/sync", [
                'mode' => 'incremental',
                'force_reprice' => true,
            ]);
        $response->assertOk()
            ->assertJsonPath('task.mode', 'full')
            ->assertJsonPath('task.force_reprice', true);

        /** @var SyncSupplySourceProducts $job */
        $job = Queue::pushed(SyncSupplySourceProducts::class)->first();
        $this->assertTrue($job->forceReprice);

        $driver = $this->createMock(SupplyDriver::class);
        $driver->method('listProducts')->willReturn([
            'items' => [new UpstreamProduct(code: 'FORCE', name: '强制重算商品', price: 100, factoryPrice: 80)],
            'total' => 1, 'page' => 1, 'has_more' => false,
        ]);
        $manager = $this->createMock(SupplyManager::class);
        $manager->method('driver')->willReturn($driver);
        $job->handle($manager, app(SupplySyncService::class));

        $product->refresh();
        $this->assertSame(110, (int) $product->price);
        $this->assertFalse((bool) $product->price_manual);
        $this->assertDatabaseHas('supply_sync_tasks', [
            'id' => $job->taskId,
            'force_reprice' => true,
            'price_updated_count' => 1,
            'manual_price_skipped_count' => 0,
        ]);
    }

    public function test_updating_unrelated_settings_does_not_dispatch_reprice(): void
    {
        Queue::fake();
        $source = $this->makeSource(['default_pricing_mode' => 'percent', 'default_markup_percent' => 150]);

        // 只改 auto_list,不动定价
        $resp = $this->withHeaders($this->adminHeaders())
            ->putJson("/api/admin/supply-sources/{$source->id}", [
                'name' => $source->name,
                'driver' => $source->driver,
                'base_url' => $source->base_url,
                'settings' => ['auto_list' => false, 'default_pricing_mode' => 'percent', 'default_markup_percent' => 150],
            ]);
        $resp->assertOk();

        Queue::assertNotPushed(SyncSupplySourceProducts::class);
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
