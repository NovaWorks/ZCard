<?php

use App\Models\PaymentChannel;
use Illuminate\Database\Migrations\Migration;
use App\Payment\Drivers\AlipayDriver;
use App\Payment\Drivers\WechatPayDriver;
use App\Payment\Drivers\EpayDriver;
use App\Payment\Drivers\UsdtDriver;
use App\Payment\Drivers\CodePayDriver;
use App\Payment\Drivers\PaypalDriver;
use App\Payment\Drivers\StripeDriver;
use App\Payment\Drivers\EpuSdtDriver;

/**
 * 预置默认支付渠道(系统基础数据,非演示数据)。
 *
 * 原本渠道数据靠 PaymentChannelSeeder 填充,但生产环境/在线更新只跑 migrate
 * 不跑 seed,导致 payment_channels 表为空,后台显示"暂无支付渠道"。
 * 这里把渠道创建放进迁移,确保任何部署/升级执行 migrate 后自动拥有这些渠道。
 *
 * 使用 updateOrCreate(幂等):已存在的渠道(含已配置/已启用的)不受影响。
 */
return new class extends Migration {
    public function up(): void
    {
        $channels = [
            ['code' => 'alipay',    'name' => '支付宝',       'driver' => AlipayDriver::class],
            ['code' => 'wechatpay', 'name' => '微信支付',     'driver' => WechatPayDriver::class],
            ['code' => 'epay',      'name' => '易支付',       'driver' => EpayDriver::class],
            ['code' => 'usdt',      'name' => 'USDT',         'driver' => UsdtDriver::class],
            ['code' => 'codepay',   'name' => '码支付',       'driver' => CodePayDriver::class],
            ['code' => 'paypal',    'name' => 'PayPal',       'driver' => PaypalDriver::class],
            ['code' => 'stripe',    'name' => 'Stripe',       'driver' => StripeDriver::class],
            ['code' => 'epusdt',    'name' => 'EpuSdt(USDT)', 'driver' => EpuSdtDriver::class],
        ];

        foreach ($channels as $index => $channel) {
            PaymentChannel::updateOrCreate(
                ['merchant_id' => 1, 'code' => $channel['code']],
                [
                    'name' => $channel['name'],
                    'driver' => $channel['driver'],
                    'config' => null,
                    'fee' => 0,
                    'fee_type' => 'percent',
                    'sort' => $index,
                    'enabled' => false,
                ]
            );
        }
    }

    public function down(): void
    {
        // 不删除渠道数据(可能已被运营配置),down 只做空实现
    }
};
