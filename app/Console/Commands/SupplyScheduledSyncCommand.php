<?php

namespace App\Console\Commands;

use App\Jobs\SyncSupplySourceProducts;
use App\Models\SupplySource;
use App\Models\SupplySyncTask;
use App\Supply\SupplyScheduleService;
use App\Supply\SupplySyncTaskState;
use App\Support\StorefrontConfig;
use Illuminate\Console\Command;

/**
 * 货源定时同步调度(替代 v1.12.72 硬编码的每小时增量同步)。
 *
 * 每分钟由 Scheduler 触发;按每个启用货源的 settings.schedule 计划判断
 * 采集商品/同步价格/同步上下架三类任务是否到期,到期则派发异步任务。
 *
 * 约定:
 * - 同一货源同一时刻只派发一个任务(防重),采集优先于价格/上下架(采集已覆盖价格与上下架);
 * - worker 成功后才记录 last_{scope}_at,失败任务下个周期可重试且不会推进增量游标;
 * - 旧版仅 auto_sync=true 的货源按「每小时增量采集」兼容。
 */
class SupplyScheduledSyncCommand extends Command
{
    protected $signature = 'supply:scheduled-sync';

    protected $description = '按货源定时计划派发商品采集/价格/上下架同步任务';

    public function handle(SupplyScheduleService $schedule): int
    {
        if (! StorefrontConfig::get('supply_enabled')) {
            $this->comment('货源对接未启用(supply_enabled=false),跳过本次调度');

            return self::SUCCESS;
        }

        $sources = SupplySource::where('status', SupplySource::STATUS_ACTIVE)->orderBy('id')->get();
        if ($sources->isEmpty()) {
            $this->comment('没有启用的货源,跳过本次调度');

            return self::SUCCESS;
        }

        $dispatched = 0;
        foreach ($sources as $source) {
            if (! $schedule->isEnabled($source)) {
                continue;
            }

            // 先回收 worker 已退出的无心跳任务,避免永久占用防重锁
            app(SupplySyncTaskState::class)->reapStale($source->id);

            foreach (SupplyScheduleService::SCOPES as $scope) {
                $cfg = $schedule->config($source, $scope);
                if (! $schedule->isDue($source, $scope, $cfg)) {
                    continue;
                }
                if ($this->hasRunningTask($source->id)) {
                    // 同源已有任务在跑(采集/价格/上下架任一),等下个周期再排
                    break;
                }

                $mode = $scope === SupplySyncTask::SCOPE_COLLECT
                    ? (string) ($cfg['mode'] ?? 'incremental')
                    : 'incremental';

                $task = SupplySyncTask::create([
                    'supply_source_id' => $source->id,
                    'mode' => $mode,
                    'scope' => $scope,
                    'force_reprice' => false,
                    'status' => SupplySyncTask::STATUS_QUEUED,
                ]);
                SyncSupplySourceProducts::dispatch($source->id, $mode, $task->id, false, $scope);
                $dispatched++;

                $this->line("  派发 scope={$scope} mode={$mode} source={$source->id}({$source->name}) task={$task->id}");

                // 采集已覆盖价格/上下架:本周期该货源不再派发其他任务
                break;
            }
        }

        $this->info("本次调度共派发 {$dispatched} 个同步任务");

        return self::SUCCESS;
    }

    private function hasRunningTask(int $sourceId): bool
    {
        return SupplySyncTask::where('supply_source_id', $sourceId)
            ->whereIn('status', [
                SupplySyncTask::STATUS_QUEUED,
                SupplySyncTask::STATUS_RUNNING,
                SupplySyncTask::STATUS_CANCELLING,
            ])
            ->exists();
    }
}
