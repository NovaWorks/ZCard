<?php

use App\Payment\Drivers\AlipayDriver;
use App\Payment\Drivers\BEpusdtDriver;
use App\Payment\Drivers\CodePayDriver;
use App\Payment\Drivers\EpayDriver;
use App\Payment\Drivers\EpuSdtDriver;
use App\Payment\Drivers\PaypalDriver;
use App\Payment\Drivers\StripeDriver;
use App\Payment\Drivers\UsdtDriver;
use App\Payment\Drivers\WechatPayDriver;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * 预置默认支付渠道(系统基础数据,非演示数据)。
 *
 * 原本渠道数据靠 PaymentChannelSeeder 填充,但生产环境/在线更新只跑 migrate
 * 不跑 seed,导致 payment_channels 表为空,后台显示"暂无支付渠道"。
 * 这里把渠道创建放进迁移,确保任何部署/升级执行 migrate 后自动拥有这些渠道。
 *
 * 使用 DB 门面直接操作(绕开 Eloquent 软删除作用域),保证幂等。
 *
 * 数据依赖修复:payment_channels.merchant_id 外键约束 → 必须先存在 id=1 的商户。
 * 但全新安装时,migrate 执行到此迁移时商户尚未创建(安装流程 Step 8 才建),
 * 直接插入 merchant_id=1 会触发外键约束失败(1452)。
 * 故此处先幂等确保默认商户(及其 owner admin 用户)存在,再插入渠道数据。
 *
 * 注意:用 DB 门面而非 Eloquent,因为 users 表用了软删除,User::firstOrCreate
 * 会漏掉已软删除的同名记录 → 撞 unique 约束(1062)。DB 层查询包含软删除记录。
 */
return new class extends Migration
{
    public function up(): void
    {
        // 1. 幂等确保默认商户(id=1)存在(避免外键约束 1452)。
        //    全新安装时 migrate 先于安装流程的「建商户」步骤执行,故在此兜底创建;
        //    安装流程/已有部署发现已存在则跳过。
        if (! DB::table('merchants')->where('id', 1)->exists()) {
            // 商户依赖 owner 用户(users 外键)。
            // 用 withTrashed 语义(DB 层查全部含软删除),避免撞 username unique 约束。
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

        // 2. 预置支付渠道(按 code 幂等,不覆盖已有配置)
        $channels = [
            ['code' => 'alipay',    'name' => '支付宝',       'driver' => AlipayDriver::class],
            ['code' => 'wechatpay', 'name' => '微信支付',     'driver' => WechatPayDriver::class],
            ['code' => 'epay',      'name' => '易支付',       'driver' => EpayDriver::class],
            ['code' => 'usdt',      'name' => 'USDT',         'driver' => UsdtDriver::class],
            ['code' => 'codepay',   'name' => '码支付',       'driver' => CodePayDriver::class],
            ['code' => 'paypal',    'name' => 'PayPal',       'driver' => PaypalDriver::class],
            ['code' => 'stripe',    'name' => 'Stripe',       'driver' => StripeDriver::class],
            ['code' => 'epusdt',    'name' => 'EpuSdt(USDT)', 'driver' => EpuSdtDriver::class],
            ['code' => 'bepusdt',    'name' => 'BEpusdt',       'driver' => BEpusdtDriver::class],
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

    public function down(): void
    {
        // 不删除渠道数据(可能已被运营配置),down 只做空实现
    }
};
