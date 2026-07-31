<?php

namespace Database\Seeders;

use App\Models\PaymentChannel;
use Illuminate\Database\Seeder;

class PaymentChannelSeeder extends Seeder
{
    /**
     * 预置 7 个支付渠道（默认全部禁用，需运营在后台配置后启用）。
     */
    public function run(): void
    {
        $channels = [
            [
                'code' => 'alipay',
                'name' => '支付宝',
                'driver' => \App\Payment\Drivers\AlipayDriver::class,
            ],
            [
                'code' => 'wechatpay',
                'name' => '微信支付',
                'driver' => \App\Payment\Drivers\WechatPayDriver::class,
            ],
            [
                'code' => 'epay',
                'name' => '易支付',
                'driver' => \App\Payment\Drivers\EpayDriver::class,
            ],
            [
                'code' => 'usdt',
                'name' => 'USDT',
                'driver' => \App\Payment\Drivers\UsdtDriver::class,
            ],
            [
                'code' => 'codepay',
                'name' => '码支付',
                'driver' => \App\Payment\Drivers\CodePayDriver::class,
            ],
            [
                'code' => 'paypal',
                'name' => 'PayPal',
                'driver' => \App\Payment\Drivers\PaypalDriver::class,
            ],
            [
                'code' => 'stripe',
                'name' => 'Stripe',
                'driver' => \App\Payment\Drivers\StripeDriver::class,
            ],
            [
                'code' => 'epusdt',
                'name' => 'EpuSdt(USDT)',
                'driver' => \App\Payment\Drivers\EpuSdtDriver::class,
            ],
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
}
