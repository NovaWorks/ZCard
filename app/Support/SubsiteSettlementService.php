<?php

namespace App\Support;

use App\Events\OrderPaid;
use App\Models\SubsiteLedgerEntry;
use App\Models\SubsiteOrderSnapshot;

/**
 * 分站利润结算(spec §7):监听 OrderPaid,按快照写冻结期账本。
 * 幂等:idempotency_key 唯一。
 */
class SubsiteSettlementService
{
    public function handle(OrderPaid $event): void
    {
        if (! config('zcard.features.sub_site')) {
            return;
        }
        if ($event->order->source === 'supply') {
            return; // 供货订单不参与分站结算(spec §4.5.1)
        }
        $order = $event->order;
        if (! $order->subsite_id) {
            return;
        }

        $snapshot = SubsiteOrderSnapshot::where('order_id', $order->id)->first();
        if (! $snapshot || ! $snapshot->profit_eligible || $snapshot->profit_amount <= 0) {
            return;
        }

        $confirmDays = (int) (StorefrontConfig::get('subsite_default_confirm_days') ?? 7);
        $availableAt = $confirmDays > 0 ? now()->addDays($confirmDays) : now();

        SubsiteLedgerEntry::create([
            'merchant_id' => $order->subsite_id,
            'order_id' => $order->id,
            'type' => 'order_profit',
            'amount' => $snapshot->profit_amount,
            'status' => $confirmDays > 0 ? 'pending' : 'available',
            'available_at' => $availableAt,
            'idempotency_key' => "order_profit:{$order->id}",
            'remark' => "分站订单 {$order->order_no} 利润",
        ]);
    }
}
