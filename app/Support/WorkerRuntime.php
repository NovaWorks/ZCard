<?php

namespace App\Support;

/**
 * 当前 PHP 进程的启动快照。
 *
 * 新 worker 在 AppServiceProvider::boot() 时写入；在线更新前已启动的旧 worker
 * 不会执行新版 boot 逻辑，因此探针可以把缺少快照识别为需重启。
 */
final class WorkerRuntime
{
    /** @var array{version:string, started_at:int}|null */
    private static ?array $snapshot = null;

    public static function boot(string $version): void
    {
        self::$snapshot ??= [
            'version' => $version,
            'started_at' => now()->timestamp,
        ];
    }

    /** @return array{version:string, started_at:int}|null */
    public static function snapshot(): ?array
    {
        return self::$snapshot;
    }
}
