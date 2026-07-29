<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// 超时关单:每5分钟扫描,关闭超时未支付订单并释放卡密
Schedule::command('orders:close-expired')->everyFiveMinutes()->withoutOverlapping();
