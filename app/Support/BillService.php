<?php

namespace App\Support;

use App\Models\Bill;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * 账单服务:记录用户余额变动 + 更新用户余额(事务+行锁)。
 * 参考 acg-faka Bill::create,记录变动后余额快照。
 */
class BillService
{
    /**
     * 记录一笔账单并更新用户余额。
     *
     * @param  int  $userId  用户ID
     * @param  int  $amountFen  金额(分,正数)
     * @param  int  $type  0=支出,1=收入
     * @param  string  $log  交易说明
     * @param  int|null  $orderId  关联订单
     * @param  int|null  $adminId  操作管理员
     * @param  bool  $countAsRecharge  是否计入累计充值(仅真实充值传 true;佣金/调账
     *                                 不计入,防刷会员等级——安全审计 M-3)
     *
     * @throws \RuntimeException 余额不足或用户不存在
     */
    public static function record(
        int $userId,
        int $amountFen,
        int $type,
        string $log,
        ?int $orderId = null,
        ?int $adminId = null,
        bool $countAsRecharge = false,
    ): Bill {
        if ($amountFen <= 0) {
            throw new \RuntimeException('金额必须大于 0');
        }

        return DB::transaction(function () use ($userId, $amountFen, $type, $log, $orderId, $adminId, $countAsRecharge) {
            $user = User::where('id', $userId)->lockForUpdate()->firstOrFail();

            if ($type === Bill::TYPE_EXPENSE) {
                if ($user->balance < $amountFen) {
                    throw new \RuntimeException('余额不足');
                }
                $user->decrement('balance', $amountFen);
            } else {
                $user->increment('balance', $amountFen);
                // 安全(M-3):仅「真实充值」计累计充值并参与会员升级——佣金、管理员
                // 调账不再计入,防止小号自购返佣折价刷会员等级(充值与消费双指标套利)。
                if ($countAsRecharge) {
                    MemberUpgradeService::addRecharge($userId, $amountFen);
                }
            }

            return Bill::create([
                'user_id' => $userId,
                'amount' => $amountFen,
                'balance_after' => $user->fresh()->balance,
                'type' => $type,
                'log' => $log,
                'order_id' => $orderId,
                'admin_id' => $adminId,
            ]);
        });
    }
}
