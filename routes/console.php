<?php

use App\Models\SubsiteLedgerEntry;
use App\Supply\NonceStore;
use App\Supply\SupplySyncTaskState;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// 超时关单:每5分钟扫描,关闭超时未支付订单并释放卡密
Schedule::command('orders:close-expired')->everyFiveMinutes()->withoutOverlapping();

// 分站利润解冻:每天扫描,将过冻结期的 pending 账本转为 available(spec §7)
Schedule::call(function () {
    SubsiteLedgerEntry::where('status', 'pending')
        ->where('available_at', '<=', now())
        ->update(['status' => 'available']);
})->daily();

// 货源定时同步:每分钟由 supply:scheduled-sync 命令按每个货源 settings.schedule
// 计划检查「采集商品/同步价格/同步上下架」是否到期并派发(取代旧的每小时硬编码增量同步)。
Schedule::command('supply:scheduled-sync')
    ->name('supply-scheduled-sync')
    ->everyMinute()
    ->withoutOverlapping();

// 同步任务看门狗：worker 异常退出时将无心跳任务收口为超时/已取消。
Schedule::call(fn () => app(SupplySyncTaskState::class)->reapStale())
    ->name('supply-sync-task-watchdog')
    ->everyMinute()
    ->withoutOverlapping();

// nonce 清理(database 模式,spec §8.5):每天清掉过期记录
Schedule::call(fn () => app(NonceStore::class)->pruneExpiredDatabase())->daily();
