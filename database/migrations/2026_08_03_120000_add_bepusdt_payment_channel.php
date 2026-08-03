<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 单独预置 BEpusdt 支付通道。
 *
 * 2026_08_02_210000_seed_default_payment_channels 已执行过的站点不会重新插入
 * 新增的 BEpusdt 通道(该迁移用 exists 幂等,且 migrations 表已记录)。
 * 故此处用独立迁移补插 BEpusdt,确保在线升级到本版本的站点也自动获得该通道。
 * 新装站点则由 seed_default_payment_channels 直接包含(数组里已加)。
 */
return new class extends Migration {
    public function up(): void
    {
        $exists = DB::table('payment_channels')
            ->where('merchant_id', 1)
            ->where('code', 'bepusdt')
            ->exists();
        if ($exists) {
            return;
        }

        // 取当前最大 sort,新通道排在最后
        $sort = (int) DB::table('payment_channels')->where('merchant_id', 1)->max('sort');

        DB::table('payment_channels')->insert([
            'merchant_id' => 1,
            'code' => 'bepusdt',
            'name' => 'BEpusdt',
            'driver' => \App\Payment\Drivers\BEpusdtDriver::class,
            'config' => null,
            'fee' => 0,
            'fee_type' => 'percent',
            'sort' => $sort + 1,
            'enabled' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('payment_channels')
            ->where('merchant_id', 1)
            ->where('code', 'bepusdt')
            ->delete();
    }
};
