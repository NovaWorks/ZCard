<?php

namespace App\Supply;

use App\Models\SupplySyncTask;
use App\Support\AppHelper;
use Throwable;

/**
 * 货源同步任务状态机。
 *
 * 所有终态更新都带状态条件，防止旧 worker 在取消/超时后迟到返回，
 * 把任务错误改回 success 或 failed。
 */
class SupplySyncTaskState
{
    public function start(SupplySyncTask $task): bool
    {
        $task->refresh();
        if ($task->status === SupplySyncTask::STATUS_CANCELLED) {
            return false;
        }
        if ($task->status === SupplySyncTask::STATUS_CANCELLING) {
            $this->finishCancelled($task);

            return false;
        }

        $now = now();
        $updated = SupplySyncTask::whereKey($task->id)
            ->where('status', SupplySyncTask::STATUS_QUEUED)
            ->update([
                'status' => SupplySyncTask::STATUS_RUNNING,
                'started_at' => $now,
                'heartbeat_at' => $now,
                'current_stage' => 'starting',
                'current_page' => 0,
                'stage_current' => 0,
                'stage_total' => 0,
                'worker_version' => AppHelper::version(),
                'error' => null,
                'error_code' => null,
                'error_context' => null,
            ]);

        return $updated === 1;
    }

    /**
     * 刷新任务心跳。返回 false 表示任务已不允许继续执行。
     *
     * @param  array<string, mixed>  $progress
     */
    public function heartbeat(SupplySyncTask $task, string $stage, array $progress = []): bool
    {
        $allowed = [
            'total_products', 'processed_products', 'created_count', 'updated_count',
            'price_updated_count', 'manual_price_skipped_count', 'deleted_count', 'current_page',
            'stage_current', 'stage_total',
        ];
        $payload = array_intersect_key($progress, array_flip($allowed));
        $payload['heartbeat_at'] = now();
        $payload['current_stage'] = $stage;

        $updated = SupplySyncTask::whereKey($task->id)
            ->where('status', SupplySyncTask::STATUS_RUNNING)
            ->update($payload);
        if ($updated === 1) {
            return true;
        }

        $status = SupplySyncTask::whereKey($task->id)->value('status');
        // MariaDB 默认返回实际变更行数；同一秒内写入相同阶段和进度时可能为 0。
        // 此时条件仍匹配 running，不能把“没有字段变化”误判成任务被取消。
        if ($status === SupplySyncTask::STATUS_RUNNING) {
            return true;
        }
        if ($status === SupplySyncTask::STATUS_CANCELLING) {
            $this->finishCancelled($task);
        }

        return false;
    }

    /**
     * 请求取消任务。审计字段只在首次状态转换时写入，重复请求不得覆盖首位操作者。
     *
     * @param  array<string, int|string|null>  $audit
     */
    public function requestCancel(SupplySyncTask $task, array $audit = []): SupplySyncTask
    {
        $now = now();
        $audit = array_intersect_key($audit, array_flip([
            'cancel_requested_by',
            'cancel_requested_by_name',
            'cancel_request_ip',
            'cancel_reason',
            'cancel_trigger',
        ]));
        if ($task->status === SupplySyncTask::STATUS_QUEUED) {
            SupplySyncTask::whereKey($task->id)
                ->where('status', SupplySyncTask::STATUS_QUEUED)
                ->update(array_merge([
                    'status' => SupplySyncTask::STATUS_CANCELLED,
                    'cancel_requested_at' => $now,
                    'current_stage' => 'cancelled',
                    'finished_at' => $now,
                ], $audit));
        } elseif ($task->status === SupplySyncTask::STATUS_RUNNING) {
            SupplySyncTask::whereKey($task->id)
                ->where('status', SupplySyncTask::STATUS_RUNNING)
                ->update(array_merge([
                    'status' => SupplySyncTask::STATUS_CANCELLING,
                    'cancel_requested_at' => $now,
                    'current_stage' => 'cancelling',
                ], $audit));
        }

        return $task->fresh();
    }

    /** @param  array<string, mixed>  $counts */
    public function succeed(SupplySyncTask $task, array $counts): bool
    {
        return SupplySyncTask::whereKey($task->id)
            ->where('status', SupplySyncTask::STATUS_RUNNING)
            ->update(array_merge($counts, [
                'status' => SupplySyncTask::STATUS_SUCCESS,
                'current_stage' => 'completed',
                'heartbeat_at' => now(),
                'error' => null,
                'error_code' => null,
                'error_context' => null,
                'finished_at' => now(),
            ])) === 1;
    }

    /** @param  array<string, mixed>  $counts */
    public function fail(SupplySyncTask $task, Throwable $e, array $counts = [], ?string $forcedCode = null): bool
    {
        $task->refresh();
        if ($task->status === SupplySyncTask::STATUS_CANCELLING) {
            $this->finishCancelled($task);

            return false;
        }
        if (in_array($task->status, [
            SupplySyncTask::STATUS_CANCELLED,
            SupplySyncTask::STATUS_TIMED_OUT,
            SupplySyncTask::STATUS_SUCCESS,
        ], true)) {
            return false;
        }

        $diagnostic = SupplySyncError::diagnose($e);
        $code = $forcedCode ?? $diagnostic['code'];
        $status = in_array($code, ['TASK_TIMEOUT', 'TASK_STALLED'], true)
            ? SupplySyncTask::STATUS_TIMED_OUT
            : SupplySyncTask::STATUS_FAILED;
        $context = array_merge($diagnostic['context'], [
            'retryable' => $diagnostic['retryable'],
        ]);

        return SupplySyncTask::whereKey($task->id)
            ->whereIn('status', [SupplySyncTask::STATUS_QUEUED, SupplySyncTask::STATUS_RUNNING])
            ->update(array_merge($counts, [
                'status' => $status,
                'current_stage' => $status,
                'heartbeat_at' => now(),
                'error' => mb_substr($diagnostic['message'], 0, 2000),
                'error_code' => $code,
                'error_context' => $context,
                'finished_at' => now(),
            ])) === 1;
    }

    public function finishCancelled(SupplySyncTask $task): bool
    {
        return SupplySyncTask::whereKey($task->id)
            ->whereIn('status', [
                SupplySyncTask::STATUS_QUEUED,
                SupplySyncTask::STATUS_RUNNING,
                SupplySyncTask::STATUS_CANCELLING,
            ])
            ->update([
                'status' => SupplySyncTask::STATUS_CANCELLED,
                'current_stage' => 'cancelled',
                'heartbeat_at' => now(),
                'finished_at' => now(),
            ]) === 1;
    }

    /**
     * 回收 worker 已异常退出或长时间无活动的任务。
     *
     * @return array{timed_out:int, cancelled:int}
     */
    public function reapStale(?int $sourceId = null): array
    {
        $staleSeconds = max(90, (int) config('zcard.supply.sync_stale_seconds', 120));
        $cancelSeconds = max(30, (int) config('zcard.supply.sync_cancel_grace_seconds', 60));
        $timedOut = 0;
        $cancelled = 0;
        $staleCutoff = now()->subSeconds($staleSeconds);

        $running = SupplySyncTask::query()
            ->where('status', SupplySyncTask::STATUS_RUNNING)
            ->when($sourceId, fn ($query) => $query->where('supply_source_id', $sourceId))
            ->where(function ($query) use ($staleCutoff) {
                $query->where('heartbeat_at', '<', $staleCutoff)
                    ->orWhere(function ($query) use ($staleCutoff) {
                        $query->whereNull('heartbeat_at')->where('started_at', '<', $staleCutoff);
                    });
            })
            ->get();

        foreach ($running as $task) {
            $error = new \RuntimeException('同步任务长时间无心跳，worker 可能已退出或上游请求卡住');
            $diagnostic = SupplySyncError::diagnose($error);
            // 在 UPDATE 时再次核对心跳，避免查询后 worker 恰好恢复活动而被看门狗误判。
            $updated = SupplySyncTask::whereKey($task->id)
                ->where('status', SupplySyncTask::STATUS_RUNNING)
                ->where(function ($query) use ($staleCutoff) {
                    $query->where('heartbeat_at', '<', $staleCutoff)
                        ->orWhere(function ($query) use ($staleCutoff) {
                            $query->whereNull('heartbeat_at')->where('started_at', '<', $staleCutoff);
                        });
                })
                ->update([
                    'status' => SupplySyncTask::STATUS_TIMED_OUT,
                    'current_stage' => SupplySyncTask::STATUS_TIMED_OUT,
                    'heartbeat_at' => now(),
                    'error' => mb_substr($diagnostic['message'], 0, 2000),
                    'error_code' => 'TASK_STALLED',
                    'error_context' => ['retryable' => true],
                    'finished_at' => now(),
                ]);
            if ($updated === 1) {
                $timedOut++;
                $task->source?->update(['last_error' => '同步任务已超时：worker 无心跳，请检查队列进程和上游网络']);
            }
        }

        $cancelling = SupplySyncTask::query()
            ->where('status', SupplySyncTask::STATUS_CANCELLING)
            ->when($sourceId, fn ($query) => $query->where('supply_source_id', $sourceId))
            ->where('cancel_requested_at', '<', now()->subSeconds($cancelSeconds))
            ->get();
        foreach ($cancelling as $task) {
            if ($this->finishCancelled($task)) {
                $cancelled++;
            }
        }

        return ['timed_out' => $timedOut, 'cancelled' => $cancelled];
    }
}
