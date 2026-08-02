<?php

namespace App\Listeners;

use App\Events\OrderPaid;
use App\Support\MemberUpgradeService;

/**
 * 订单支付成功后,累加购买者的累计消费并按当前升级依据尝试升级会员等级。
 * 仅对注册用户(user_id 非空)生效;游客订单不计入。
 * 监听器在 markPaid 事务内同步执行,与订单状态变更原子一致。
 */
class UpgradeUserGroupOnOrderPaid
{
    public function handle(OrderPaid $event): void
    {
        $order = $event->order;

        // 游客订单(user_id=null)不计入累计消费
        if (! $order->user_id) {
            return;
        }

        // amount 单位为分(与 users.total_consumption 一致)
        MemberUpgradeService::addConsumption($order->user_id, (int) $order->amount);
    }
}
