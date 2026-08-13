<?php

namespace App\Jobs;

use App\Models\Product;
use App\Models\SupplySource;
use App\Models\SupplySyncTask;
use App\Supply\Exceptions\SyncTaskCancelledException;
use App\Supply\SupplyManager;
use App\Supply\SupplySyncError;
use App\Supply\SupplySyncService;
use App\Supply\SupplySyncTaskState;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * 商品同步任务(spec §6.6 + 异步化改造)。
 *
 * 异步入库:由队列 worker 执行(生产环境必须跑 queue:work),后台任务表
 * (supply_sync_tasks)记录状态/进度,支持取消与重新同步。
 *
 * 状态流转:queued → running → success | failed | timed_out；
 * 取消流转:queued → cancelled，running → cancelling → cancelled。
 */
class SyncSupplySourceProducts implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    // 必须小于 queue.php 的 retry_after(默认 960 秒)，避免超时前任务被重复领取。
    public int $timeout = 900;

    public int $tries = 1;

    public bool $failOnTimeout = true;

    public function __construct(
        public readonly int $sourceId,
        public readonly string $mode = 'incremental',
        public readonly ?int $taskId = null,
        public readonly bool $forceReprice = false,
    ) {}

    public function handle(
        SupplyManager $manager,
        SupplySyncService $sync,
        ?SupplySyncTaskState $states = null,
    ): void {
        $states ??= app(SupplySyncTaskState::class);
        $task = $this->task();
        $source = SupplySource::find($this->sourceId);
        if (! $source || ! $source->isActive()) {
            $states->fail($task, new \RuntimeException('货源不存在或已停用'));

            return;
        }

        if (! $states->start($task)) {
            return;
        }

        $page = 1;
        $created = $updated = $priceUpdated = $manualPriceSkipped = $deleted = $processed = $total = 0;
        $reportedTotal = null;
        $seenCodes = null;

        try {
            $this->pulse($states, $task, 'connecting');
            $driver = $manager->driver($source);
            $updatedAfter = $this->mode === 'incremental' ? $source->last_synced_at : null;
            // 完整同步，或驱动本身不支持 updatedAfter（acg-faka/ZCard 每次均返回完整快照）时，
            // 本次集合具有权威性，可检测上游已删除/消失的商品并执行软删除。
            $seenCodes = $this->mode === 'full' || ! $driver->supportsIncrementalProductSync()
                ? []
                : null;

            do {
                $this->pulse($states, $task, 'fetching_products', ['current_page' => $page]);

                // 同步模式补查真实库存(fetchStock=true):上游 items 对手动发货商品
                // 不返回 stock,逐个补查(并发10)让库存准确
                $result = $driver->listProducts(
                    $updatedAfter,
                    $page,
                    fetchStock: true,
                    progress: function (string $stage, int $current, int $stageTotal) use ($states, $task, $page): void {
                        $this->pulse($states, $task, $stage, [
                            'current_page' => $page,
                            'stage_current' => $current,
                            'stage_total' => $stageTotal,
                        ]);
                    },
                );
                $this->pulse($states, $task, 'saving_products', ['current_page' => $page]);
                if (! array_key_exists('items', $result) || ! is_array($result['items'])) {
                    throw new \RuntimeException("上游第 {$page} 页商品数据格式异常,已停止同步以避免误删");
                }
                if ($seenCodes !== null && ! array_key_exists('total', $result)) {
                    throw new \RuntimeException("上游第 {$page} 页缺少商品总数,已停止完整同步以避免误删");
                }
                $items = $result['items'] ?? [];
                $total += count($items);
                if (array_key_exists('total', $result)) {
                    $reportedTotal = max($reportedTotal ?? 0, (int) $result['total']);
                }

                foreach ($items as $dto) {
                    if ($processed % 10 === 0) {
                        $this->pulse($states, $task, 'saving_products', array_merge(
                            $this->counts($total, $processed, $created, $updated, $priceUpdated, $manualPriceSkipped, $deleted),
                            ['current_page' => $page],
                        ));
                    }

                    if ($seenCodes !== null) {
                        $seenCodes[] = $dto->code;
                    }

                    $beforeProduct = Product::where('upstream_source_id', $source->id)
                        ->where('upstream_product_code', $dto->code)
                        ->first(['price', 'price_manual']);
                    $before = $beforeProduct?->price;
                    $exists = $beforeProduct !== null;
                    $manualPriceProtected = $exists
                        && ! $this->forceReprice
                        && (bool) $beforeProduct->price_manual
                        && (bool) ($source->settings['auto_sync_price'] ?? true)
                        && (string) ($source->settings['default_pricing_mode'] ?? 'percent') !== 'pending';

                    $sync->upsertProduct($source, $dto, forcePrice: $this->forceReprice);
                    if (! $dto->isActive) {
                        if ($exists) {
                            $deleted++;
                        }
                    } elseif ($exists) {
                        $updated++;
                        if ($manualPriceProtected) {
                            $manualPriceSkipped++;
                        }
                        // 价格核对:同步后价格与同步前不一致 → 计数(上游调价跟随生效)
                        $after = Product::where('upstream_source_id', $source->id)
                            ->where('upstream_product_code', $dto->code)
                            ->value('price');
                        if ($after !== null && (int) $after !== (int) $before) {
                            $priceUpdated++;
                        }
                    } else {
                        $created++;
                    }
                    $processed++;

                    // 每 10 个商品刷新进度，同时把取消响应延迟限制在很小批次内。
                    if ($processed % 10 === 0) {
                        $this->pulse(
                            $states,
                            $task,
                            'saving_products',
                            $this->counts($total, $processed, $created, $updated, $priceUpdated, $manualPriceSkipped, $deleted),
                        );
                    }
                }

                $page++;
            } while (! empty($result['has_more']));

            // 全量核对前先验证分页完整性。若上游声称的总数大于实际处理数，
            // 说明 has_more/分页响应不完整，此时宁可任务失败也不能批量误删。
            if ($seenCodes !== null) {
                $this->pulse(
                    $states,
                    $task,
                    'reconciling_products',
                    $this->counts($total, $processed, $created, $updated, $priceUpdated, $manualPriceSkipped, $deleted),
                );
                if ($reportedTotal !== null && $processed < $reportedTotal) {
                    throw new \RuntimeException(
                        "上游商品分页不完整:应拉取 {$reportedTotal} 个,实际仅 {$processed} 个,已停止删除失效商品"
                    );
                }

                // 本次完整集合未出现的本地上游商品视为失效，使用软删除保留订单关联；
                // 同一 code 后续重新出现时 SupplySyncService 会自动恢复原记录。
                $deleted += $sync->deleteMissingProducts($source, $seenCodes);
            }

            // 避免取消请求恰好发生在核对阶段时，仍把货源标记为同步成功。
            $this->pulse(
                $states,
                $task,
                'finalizing',
                $this->counts($total, $processed, $created, $updated, $priceUpdated, $manualPriceSkipped, $deleted),
            );
            $source->update(['last_synced_at' => now(), 'last_error' => null]);
            Log::info("supply sync done source={$source->id} created={$created} updated={$updated} priceUpdated={$priceUpdated} manualPriceSkipped={$manualPriceSkipped} deleted={$deleted}");

            $states->succeed(
                $task,
                $this->counts($total, $processed, $created, $updated, $priceUpdated, $manualPriceSkipped, $deleted),
            );
        } catch (SyncTaskCancelledException) {
            $states->finishCancelled($task);
            Log::info("supply sync cancelled source={$source->id} task={$task->id}");
        } catch (Throwable $e) {
            $diagnostic = SupplySyncError::diagnose($e);
            $msg = $diagnostic['message'];
            $source->update(['last_error' => mb_strlen($msg) > 500 ? mb_substr($msg, 0, 500).'…' : $msg]);
            Log::error("supply sync failed source={$source->id} code={$diagnostic['code']}: {$e->getMessage()}");

            $states->fail(
                $task,
                $e,
                $this->counts($total, $processed, $created, $updated, $priceUpdated, $manualPriceSkipped, $deleted),
            );
            throw $e;
        }
    }

    /** worker 超时或进程级失败时的最终兜底。 */
    public function failed(?Throwable $e): void
    {
        if ($this->taskId === null) {
            return;
        }

        $task = SupplySyncTask::find($this->taskId);
        if (! $task) {
            return;
        }

        $error = $e ?? new \RuntimeException('队列 worker 异常退出');
        app(SupplySyncTaskState::class)->fail($task, $error);
        $diagnostic = SupplySyncError::diagnose($error);
        SupplySource::whereKey($this->sourceId)->update([
            'last_error' => mb_substr($diagnostic['message'], 0, 500),
        ]);
    }

    /** 取任务记录;定时调度未传 taskId 时自动创建(便于统一查看历史) */
    private function task(): SupplySyncTask
    {
        if ($this->taskId !== null) {
            return SupplySyncTask::findOrFail($this->taskId);
        }

        return SupplySyncTask::create([
            'supply_source_id' => $this->sourceId,
            'mode' => $this->mode,
            'force_reprice' => $this->forceReprice,
            'status' => SupplySyncTask::STATUS_QUEUED,
        ]);
    }

    /** @param  array<string, mixed>  $progress */
    private function pulse(
        SupplySyncTaskState $states,
        SupplySyncTask $task,
        string $stage,
        array $progress = [],
    ): void {
        if (! $states->heartbeat($task, $stage, $progress)) {
            throw new SyncTaskCancelledException;
        }
    }

    /** @return array<string, int> */
    private function counts(
        int $total,
        int $processed,
        int $created,
        int $updated,
        int $priceUpdated,
        int $manualPriceSkipped,
        int $deleted,
    ): array {
        return [
            'total_products' => $total,
            'processed_products' => $processed,
            'created_count' => $created,
            'updated_count' => $updated,
            'price_updated_count' => $priceUpdated,
            'manual_price_skipped_count' => $manualPriceSkipped,
            'deleted_count' => $deleted,
        ];
    }
}
