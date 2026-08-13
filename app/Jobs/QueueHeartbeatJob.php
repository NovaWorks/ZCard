<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;

/**
 * 队列探针(检查 queue:work 是否在运行)。
 * 派发后若 worker 正常,handle 会立即执行并刷新心跳时间戳;
 * 前端轮询心跳时间,超过阈值即判定队列未开启。
 */
class QueueHeartbeatJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function handle(): void
    {
        Cache::put('queue:heartbeat', now()->timestamp, 60);
    }
}
