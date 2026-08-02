<?php

namespace App\Support;

use App\Events\OrderPaid;
use App\Models\Bill;
use App\Models\Commission;
use App\Models\User;

/**
 * 三级分销佣金服务(spec 阶段一)。
 * 监听 OrderPaid:按毛利 × 每级费率向上追溯最多 3 级发佣。
 * 仅登录用户订单触发;游客不发。幂等:(order_id,tier) 唯一。
 */
class CommissionService
{
    public function handle(OrderPaid $event): void
    {
        if (! config('zcard.features.distribution')) {
            return;
        }
        if (! StorefrontConfig::get('distribution_enabled')) {
            return;
        }
        if ($event->order->source === 'supply') {
            return; // 供货订单不参与分销(spec §4.5.1)
        }
        if ($event->order->subsite_id) {
            return; // 分站订单不发分销佣金(spec §7.4 互斥)
        }

        $order = $event->order;
        $buyerId = $order->user_id;
        if (! $buyerId) {
            return; // 游客不发佣
        }

        // 幂等:该订单已发过佣则跳过
        if (Commission::where('order_id', $order->id)->exists()) {
            return;
        }

        $profit = (int) $order->amount - (int) $order->cost; // 毛利(分)
        if ($profit <= 0) {
            return;
        }

        $rates = [
            1 => (float) StorefrontConfig::get('distribution_rate_l1'),
            2 => (float) StorefrontConfig::get('distribution_rate_l2'),
            3 => (float) StorefrontConfig::get('distribution_rate_l3'),
        ];

        $buyer = User::find($buyerId);
        if (! $buyer) {
            return;
        }

        $current = $buyer->parent; // L1
        for ($tier = 1; $tier <= 3 && $current; $tier++) {
            if ($current->id === $buyerId) {
                break; // 自购拦截(防止环形引用)
            }
            $rate = $rates[$tier] ?? 0;
            $amount = (int) round($profit * $rate / 100);
            if ($amount > 0) {
                Commission::create([
                    'order_id' => $order->id,
                    'buyer_id' => $buyerId,
                    'referrer_id' => $current->id,
                    'tier' => $tier,
                    'rate' => $rate,
                    'base_amount' => $profit,
                    'amount' => $amount,
                    'status' => 'available',
                ]);
                BillService::record(
                    $current->id,
                    $amount,
                    Bill::TYPE_INCOME,
                    "分销佣金(订单 {$order->order_no} L{$tier})",
                    $order->id
                );
            }
            $current = $current->parent; // 下一级
        }
    }
}
