<?php

use App\Jobs\SyncSupplySourceProducts;
use App\Models\SubsiteLedgerEntry;
use App\Models\SupplySource;
use App\Supply\NonceStore;
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

// 货源商品自动同步(spec §6.6) —— 每小时跑增量,只对开启自动同步的 active 货源
// 注意:JSON_EXTRACT 为 MySQL 专属语法(生产环境),SQLite 测试环境不会执行调度
Schedule::call(function () {
    if (! config('zcard.features.supply')) {
        return;
    }
    SupplySource::where('status', 'active')
        ->whereRaw("JSON_EXTRACT(settings, '$.auto_sync') = true")
        ->each(fn ($s) => SyncSupplySourceProducts::dispatch($s->id, 'incremental'));
})->hourly();

// nonce 清理(database 模式,spec §8.5):每天清掉过期记录
Schedule::call(fn () => app(NonceStore::class)->pruneExpiredDatabase())->daily();

