<?php

use App\Payment\Drivers\OkPayDriver;
use App\Payment\Drivers\TokenPayDriver;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 新增 OKPay / TokenPay 虚拟货币支付通道(参考 dujiao-next 对接)。
 */
return new class extends Migration
{
    public function up(): void
    {
        $channels = [
            ['code' => 'okpay', 'name' => 'OKPay(USDT/TRX)', 'driver' => OkPayDriver::class],
            ['code' => 'tokenpay', 'name' => 'TokenPay(USDT)', 'driver' => TokenPayDriver::class],
        ];

        foreach ($channels as $channel) {
            $exists = DB::table('payment_channels')
                ->where('code', $channel['code'])
                ->exists();
            if ($exists) {
                continue;
            }

            $sort = (int) DB::table('payment_channels')->where('merchant_id', 1)->max('sort');

            DB::table('payment_channels')->insert([
                'merchant_id' => 1,
                'code' => $channel['code'],
                'name' => $channel['name'],
                'driver' => $channel['driver'],
                'config' => null,
                'fee' => 0,
                'fee_type' => 'percent',
                'sort' => $sort + 1,
                'enabled' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::table('payment_channels')->whereIn('code', ['okpay', 'tokenpay'])->delete();
    }
};
