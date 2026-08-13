<?php

namespace Database\Seeders;

use App\Payment\Drivers\AlipayDriver;
use App\Payment\Drivers\BEpusdtDriver;
use App\Payment\Drivers\CodePayDriver;
use App\Payment\Drivers\EpayDriver;
use App\Payment\Drivers\EpuSdtDriver;
use App\Payment\Drivers\PaypalDriver;
use App\Payment\Drivers\StripeDriver;
use App\Payment\Drivers\UsdtDriver;
use App\Payment\Drivers\WechatPayDriver;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class PaymentChannelSeeder extends Seeder
{
    /**
     * 预置支付渠道(默认全部禁用,需运营在后台配置后启用)。
     *
     * 数据依赖:payment_channels.merchant_id 外键约束 → 必须先存在 id=1 的商户。
     * 用 DB 门面直接操作(绕开 Eloquent 软删除作用域),保证幂等:
     * users 表用软删除,User::firstOrCreate 会漏掉已软删除的同名记录 → 撞 unique 约束。
     */
    public function run(): void
    {
        if (! DB::table('merchants')->where('id', 1)->exists()) {
            $adminId = DB::table('users')->where('username', 'admin')->value('id');
            if (! $adminId) {
                // 安全(C-1):占位管理员必须不可登录——随机密码 + status=0(禁用)。
                // 账号激活与密码设置只由安装向导(Web/CLI)完成。
                $adminId = DB::table('users')->insertGetId([
                    'username' => 'admin',
                    'name' => 'Super Admin',
                    'email' => 'admin@example.com',
                    'password' => Hash::make(bin2hex(random_bytes(32))),
                    'status' => 0,
                    'password_changed_at' => null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
            DB::table('merchants')->insert([
                'id' => 1,
                'user_id' => $adminId,
                'name' => '默认商户',
                'slug' => 'default',
                'status' => 1,
                'commission_rate' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $channels = [
            ['code' => 'alipay',    'name' => '支付宝',       'driver' => AlipayDriver::class],
            ['code' => 'wechatpay', 'name' => '微信支付',     'driver' => WechatPayDriver::class],
            ['code' => 'epay',      'name' => '易支付',       'driver' => EpayDriver::class],
            ['code' => 'usdt',      'name' => 'USDT',         'driver' => UsdtDriver::class],
            ['code' => 'codepay',   'name' => '码支付',       'driver' => CodePayDriver::class],
            ['code' => 'paypal',    'name' => 'PayPal',       'driver' => PaypalDriver::class],
            ['code' => 'stripe',    'name' => 'Stripe',       'driver' => StripeDriver::class],
            ['code' => 'epusdt',    'name' => 'EpuSdt(USDT)', 'driver' => EpuSdtDriver::class],
            ['code' => 'bepusdt',   'name' => 'BEpusdt',       'driver' => BEpusdtDriver::class],
        ];

        foreach ($channels as $index => $channel) {
            $exists = DB::table('payment_channels')
                ->where('merchant_id', 1)
                ->where('code', $channel['code'])
                ->exists();
            if (! $exists) {
                DB::table('payment_channels')->insert([
                    'merchant_id' => 1,
                    'code' => $channel['code'],
                    'name' => $channel['name'],
                    'driver' => $channel['driver'],
                    'config' => null,
                    'fee' => 0,
                    'fee_type' => 'percent',
                    'sort' => $index,
                    'enabled' => false,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }
}
