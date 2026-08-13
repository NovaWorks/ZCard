<?php

namespace App\Jobs;

use App\Models\Product;
use App\Models\SupplySource;
use App\Models\SupplySyncTask;
use App\Supply\SupplyManager;
use App\Supply\SupplySyncService;
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
 * 状态流转:queued → running → success | failed | cancelled。
 * 取消:页面置 cancelled 标记,Job 每页拉取前检查,感知后立即中止。
 */
class SyncSupplySourceProducts implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 3600;

    public function __construct(
        public readonly int $sourceId,
        public readonly string $mode = 'incremental',
        public readonly ?int $taskId = null,
        public readonly bool $forceReprice = false,
    ) {}

    public function handle(SupplyManager $manager, SupplySyncService $sync): void
    {
        $source = SupplySource::find($this->sourceId);
        if (! $source || ! $source->isActive()) {
            $this->finish(SupplySyncTask::STATUS_FAILED, null, '货源不存在或已停用');

            return;
        }

        $task = $this->task();
        $this->markRunning($task);

        // 排队期间已被取消 → 直接中止(不再拉取)
        if ($task->fresh()->status === SupplySyncTask::STATUS_CANCELLED) {
            $this->finish(SupplySyncTask::STATUS_CANCELLED, $task);

            return;
        }

        $page = 1;
        $created = $updated = $priceUpdated = $manualPriceSkipped = $hidden = $processed = $total = 0;
        // 全量同步:本次拉取到的全部商品 code,用于检测上游已删除/消失的商品 → 标隐藏
        $seenCodes = $this->mode === 'full' ? [] : null;

        try {
            $driver = $manager->driver($source);
            $updatedAfter = $this->mode === 'incremental' ? $source->last_synced_at : null;

            do {
                // 取消检查:页面点取消后,立即停止拉取
                if ($task->fresh()->status === SupplySyncTask::STATUS_CANCELLED) {
                    $this->finish(SupplySyncTask::STATUS_CANCELLED, $task);

                    return;
                }

                // 同步模式补查真实库存(fetchStock=true):上游 items 对手动发货商品
                // 不返回 stock,逐个补查(并发10)让库存准确
                $result = $driver->listProducts($updatedAfter, $page, fetchStock: true);
                $items = $result['items'] ?? [];
                $total += count($items);

                foreach ($items as $dto) {
                    if ($task->fresh()->status === SupplySyncTask::STATUS_CANCELLED) {
                        $this->finish(SupplySyncTask::STATUS_CANCELLED, $task);

                        return;
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
                    if ($exists) {
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
                    if (! $dto->isActive) {
                        $hidden++;
                    }
                    $processed++;

                    // 每 50 个商品刷一次进度,避免频繁写库
                    if ($processed % 50 === 0) {
                        $task->update([
                            'status' => SupplySyncTask::STATUS_RUNNING,
                            'total_products' => $total,
                            'processed_products' => $processed,
                            'created_count' => $created,
                            'updated_count' => $updated,
                            'price_updated_count' => $priceUpdated,
                            'manual_price_skipped_count' => $manualPriceSkipped,
                            'hidden_count' => $hidden,
                        ]);
                    }
                }

                $page++;
            } while (! empty($result['has_more']));

            // 全量核对:本次拉取未出现(上游已删除/彻底下架)的本地商品 → 标隐藏
            if ($seenCodes !== null) {
                $gone = Product::where('upstream_source_id', $source->id)
                    ->where('status', true)
                    ->where('hide', false)
                    ->whereNotIn('upstream_product_code', $seenCodes)
                    ->whereNotNull('upstream_product_code')
                    ->get(['id']);
                if ($gone->isNotEmpty()) {
                    Product::whereIn('id', $gone->pluck('id'))
                        ->update(['hide' => true]);
                    $hidden += $gone->count();
                }
            }

            $source->update(['last_synced_at' => now(), 'last_error' => null]);
            Log::info("supply sync done source={$source->id} created={$created} updated={$updated} priceUpdated={$priceUpdated} manualPriceSkipped={$manualPriceSkipped} hidden={$hidden}");

            $this->finish(SupplySyncTask::STATUS_SUCCESS, $task, null, [
                'total_products' => $total,
                'processed_products' => $processed,
                'created_count' => $created,
                'updated_count' => $updated,
                'price_updated_count' => $priceUpdated,
                'manual_price_skipped_count' => $manualPriceSkipped,
                'hidden_count' => $hidden,
            ]);
        } catch (Throwable $e) {
            // last_error 截断(界面展示用,避免长 SQL/堆栈刷屏)
            $msg = $e->getMessage();
            $source->update(['last_error' => mb_strlen($msg) > 500 ? mb_substr($msg, 0, 500).'…' : $msg]);
            Log::error("supply sync failed source={$source->id}: {$e->getMessage()}");

            $this->finish(SupplySyncTask::STATUS_FAILED, $task, $msg, [
                'total_products' => $total,
                'processed_products' => $processed,
                'created_count' => $created,
                'updated_count' => $updated,
                'price_updated_count' => $priceUpdated,
                'manual_price_skipped_count' => $manualPriceSkipped,
                'hidden_count' => $hidden,
            ]);
            throw $e;
        }
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

    private function markRunning(SupplySyncTask $task): void
    {
        // 排队期间已被取消 → 保持 cancelled,不得覆盖为 running
        if ($task->status === SupplySyncTask::STATUS_CANCELLED) {
            return;
        }

        $task->update([
            'status' => SupplySyncTask::STATUS_RUNNING,
            'started_at' => now(),
            'error' => null,
        ]);
    }

    /** 结束任务:成功/失败/取消统一收口 */
    private function finish(string $status, ?SupplySyncTask $task, ?string $error = null, array $counts = []): void
    {
        if (! $task) {
            return;
        }

        $task->update(array_merge($counts, [
            'status' => $status,
            'error' => $error !== null ? mb_substr($error, 0, 2000) : null,
            'finished_at' => now(),
        ]));
    }
}
