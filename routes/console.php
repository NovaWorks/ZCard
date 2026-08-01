<?php

use App\Models\SubsiteLedgerEntry;
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
