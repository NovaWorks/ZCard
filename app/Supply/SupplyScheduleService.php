<?php

namespace App\Supply;

use App\Models\SupplySource;
use App\Models\SupplySyncTask;

/**
 * 货源定时任务计划(spec §6.6 增强)。
 *
 * 每个货源可在 settings.schedule 里配置三类任务(采集商品/同步价格/同步上下架)各自的
 * 启用状态、执行间隔(分钟)与时间窗口(几点到几点,可多条,留空=全天)，
 * 由 supply:scheduled-sync 命令每分钟检查并按需派发 SyncSupplySourceProducts。
 *
 * settings.schedule 结构:
 * {
 *   "enabled": true,            // 定时任务总开关
 *   "request_delay": 0,         // 每次请求上游间隔(秒),0=不限(防止上游限流)
 *   "collect": { "enabled": true, "mode": "incremental", "interval": 360, "windows": [] },
 *   "price":   { "enabled": true, "interval": 30,  "windows": [] },
 *   "status":  { "enabled": true, "interval": 60,  "windows": [] }
 * }
 *
 * 旧版兼容:仅配置了 settings.auto_sync=true 的货源(无 schedule 键)按
 * 「每小时增量采集」处理,保持 v1.12.72 的行为不变。
 */
class SupplyScheduleService
{
    /** 三类任务的键名(顺序即优先级:同一次检查中先到期的先派发) */
    public const SCOPES = [
        SupplySyncTask::SCOPE_COLLECT,
        SupplySyncTask::SCOPE_PRICE,
        SupplySyncTask::SCOPE_STATUS,
    ];

    /** 旧版 auto_sync=true 的兜底间隔(分钟):与 v1.12.72 每小时增量同步一致 */
    public const LEGACY_INTERVAL_MINUTES = 60;

    /** 新配置的默认间隔(分钟) */
    public const DEFAULT_INTERVALS = [
        SupplySyncTask::SCOPE_COLLECT => 360, // 每 6 小时采集一次
        SupplySyncTask::SCOPE_PRICE => 30,    // 每 30 分钟同步价格
        SupplySyncTask::SCOPE_STATUS => 60,   // 每 60 分钟同步上下架
    ];

    /**
     * 定时任务总开关。优先读 schedule.enabled;无 schedule 时兼容旧版 auto_sync。
     */
    public function isEnabled(SupplySource $source): bool
    {
        $settings = $source->settings ?? [];
        if (isset($settings['schedule']) && is_array($settings['schedule'])) {
            return (bool) ($settings['schedule']['enabled'] ?? false);
        }

        return (bool) ($settings['auto_sync'] ?? false);
    }

    /**
     * 单个任务的计划配置(含默认值与旧版兜底)。
     *
     * @return array{enabled:bool, interval:int|null, windows:array<int, array{start:string, end:string}>, mode?:string}
     */
    public function config(SupplySource $source, string $scope): array
    {
        $settings = $source->settings ?? [];
        $schedule = $settings['schedule'] ?? null;

        // 旧版:只有采集默认开启,其余两类默认关闭
        if (! is_array($schedule)) {
            if ($scope === SupplySyncTask::SCOPE_COLLECT) {
                return $this->normalize([
                    'enabled' => (bool) ($settings['auto_sync'] ?? false),
                    'mode' => 'incremental',
                    'interval' => self::LEGACY_INTERVAL_MINUTES,
                    'windows' => [],
                ], $scope);
            }

            return $this->normalize(['enabled' => false, 'interval' => null, 'windows' => []], $scope);
        }

        $cfg = is_array($schedule[$scope] ?? null) ? $schedule[$scope] : [];

        return $this->normalize($cfg, $scope);
    }

    /**
     * 是否到期该派发:启用 + 在时间窗口内 + 距上次执行超过间隔。
     *
     * @param  array{enabled:bool, interval:int|null, windows:array<int, array{start:string, end:string}>}  $cfg
     */
    public function isDue(SupplySource $source, string $scope, array $cfg): bool
    {
        if (empty($cfg['enabled'])) {
            return false;
        }
        if (! $this->inWindow($cfg['windows'] ?? [])) {
            return false;
        }
        $interval = (int) ($cfg['interval'] ?? 0);
        if ($interval <= 0) {
            return false;
        }
        $last = $source->{$this->lastRunColumn($scope)};
        if ($last === null) {
            return true;
        }

        return $last->lte(now()->subMinutes($interval));
    }

    /** scope → 上次执行时间列名(注意 price/status 的列名带 sync 后缀,不能简单拼接) */
    public function lastRunColumn(string $scope): string
    {
        return match ($scope) {
            SupplySyncTask::SCOPE_PRICE => 'last_price_sync_at',
            SupplySyncTask::SCOPE_STATUS => 'last_status_sync_at',
            default => 'last_collect_at',
        };
    }

    /**
     * 当前时间是否落在任一窗口内;windows 为空表示全天。
     *
     * @param  array<int, array{start:string, end:string}>  $windows
     */
    public function inWindow(array $windows): bool
    {
        if ($windows === []) {
            return true;
        }
        $now = now()->format('H:i');
        foreach ($windows as $w) {
            $start = (string) ($w['start'] ?? '');
            $end = (string) ($w['end'] ?? '');
            if ($start === '' || $end === '') {
                continue;
            }
            if ($this->timeInRange($now, $start, $end)) {
                return true;
            }
        }

        return false;
    }

    /** HH:mm 字符串比较(含跨天窗口,如 22:00-06:00) */
    private function timeInRange(string $time, string $start, string $end): bool
    {
        if ($start <= $end) {
            return $time >= $start && $time <= $end;
        }

        return $time >= $start || $time <= $end;
    }

    /**
     * 规范化单任务配置,补齐默认值。
     *
     * @param  array<string, mixed>  $cfg
     * @return array{enabled:bool, interval:int|null, windows:array<int, array{start:string, end:string}>, mode?:string}
     */
    private function normalize(array $cfg, string $scope): array
    {
        $defaultEnabled = ! empty($cfg['enabled']);
        $defaultInterval = array_key_exists('interval', $cfg) ? (int) $cfg['interval'] : (self::DEFAULT_INTERVALS[$scope] ?? null);

        return [
            'enabled' => $defaultEnabled,
            'mode' => $scope === SupplySyncTask::SCOPE_COLLECT
                ? (in_array($cfg['mode'] ?? null, ['incremental', 'full'], true) ? $cfg['mode'] : 'incremental')
                : null,
            'interval' => $defaultInterval > 0 ? $defaultInterval : null,
            'windows' => $this->normalizeWindows($cfg['windows'] ?? []),
        ];
    }

    /**
     * @return array<int, array{start:string, end:string}>
     */
    private function normalizeWindows(mixed $windows): array
    {
        if (! is_array($windows)) {
            return [];
        }
        $out = [];
        foreach ($windows as $w) {
            if (! is_array($w)) {
                continue;
            }
            $start = (string) ($w['start'] ?? '');
            $end = (string) ($w['end'] ?? '');
            if (preg_match('/^\d{2}:\d{2}$/', $start) && preg_match('/^\d{2}:\d{2}$/', $end)) {
                $out[] = ['start' => $start, 'end' => $end];
            }
        }

        return $out;
    }
}
