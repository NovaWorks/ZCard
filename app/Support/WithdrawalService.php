<?php

namespace App\Support;

use App\Models\Bill;
use App\Models\User;
use App\Models\Withdrawal;
use Illuminate\Support\Facades\DB;

/**
 * 提现服务。
 * 流程:用户发起(扣余额+创建待审) → 管理员审核(通过/驳回退钱)。
 * 参考 acg-faka Cash + Bill 模型。
 */
class WithdrawalService
{
    /**
     * 用户发起提现申请。
     * 事务内:校验余额 → 扣除余额(amount+fee) → 创建待审记录 → 写账单。
     */
    public static function request(
        int $userId,
        int $amountFen,
        string $method,
        string $account,
        string $accountName,
    ): Withdrawal {
        if ($amountFen <= 0) {
            throw new \RuntimeException(__('messages.withdrawal.amount_invalid'));
        }

        $minFen = (int) ((float) (StorefrontConfig::get('cash_min') ?? 100) * 100);
        $feeFen = (int) ((float) (StorefrontConfig::get('cash_fee') ?? 5) * 100);

        if ($amountFen < $minFen) {
            throw new \RuntimeException(__('messages.withdrawal.below_min', ['min' => $minFen]));
        }

        // 校验提现方式开关
        $methodEnabled = match ($method) {
            'alipay' => StorefrontConfig::get('cash_type_alipay'),
            'wechat' => StorefrontConfig::get('cash_type_wechat'),
            'usdt' => StorefrontConfig::get('cash_type_usdt'),
            default => false,
        };
        if (! $methodEnabled) {
            throw new \RuntimeException(__('messages.withdrawal.method_disabled'));
        }

        $totalDeduct = $amountFen + $feeFen;

        return DB::transaction(function () use ($userId, $amountFen, $feeFen, $totalDeduct, $method, $account, $accountName) {
            $user = User::where('id', $userId)->lockForUpdate()->firstOrFail();

            if ($user->balance < $totalDeduct) {
                throw new \RuntimeException(__('messages.withdrawal.insufficient_balance'));
            }

            $user->decrement('balance', $totalDeduct);

            $withdrawal = Withdrawal::create([
                'user_id' => $userId,
                'amount' => $amountFen,
                'actual_amount' => $amountFen, // 到账金额=提现金额(手续费已额外扣除)
                'fee' => $feeFen,
                'method' => $method,
                'account' => $account,
                'account_name' => $accountName,
                'status' => Withdrawal::STATUS_PENDING,
            ]);

            // 写账单(支出)
            Bill::create([
                'user_id' => $userId,
                'amount' => $totalDeduct,
                'balance_after' => $user->fresh()->balance,
                'type' => Bill::TYPE_EXPENSE,
                'log' => "提现申请(#{$withdrawal->id})",
            ]);

            return $withdrawal;
        });
    }

    /**
     * 管理员审核通过。
     * 只改状态(实际打款由管理员线下完成)。
     */
    public static function approve(int $withdrawalId, int $adminId): Withdrawal
    {
        return DB::transaction(function () use ($withdrawalId, $adminId) {
            $w = Withdrawal::where('id', $withdrawalId)->lockForUpdate()->firstOrFail();
            if ($w->status !== Withdrawal::STATUS_PENDING) {
                throw new \RuntimeException('该记录无法操作');
            }
            $w->update([
                'status' => Withdrawal::STATUS_APPROVED,
                'admin_id' => $adminId,
                'processed_at' => now(),
            ]);
            return $w;
        });
    }

    /**
     * 管理员审核驳回。
     * 退还余额(amount+fee) + 写账单(收入)。
     */
    public static function reject(int $withdrawalId, int $adminId, string $reason): Withdrawal
    {
        return DB::transaction(function () use ($withdrawalId, $adminId, $reason) {
            $w = Withdrawal::where('id', $withdrawalId)->lockForUpdate()->firstOrFail();
            if ($w->status !== Withdrawal::STATUS_PENDING) {
                throw new \RuntimeException('该记录无法操作');
            }

            $refundAmount = $w->amount + $w->fee;
            $user = User::where('id', $w->user_id)->lockForUpdate()->firstOrFail();
            $user->increment('balance', $refundAmount);

            Bill::create([
                'user_id' => $w->user_id,
                'amount' => $refundAmount,
                'balance_after' => $user->fresh()->balance,
                'type' => Bill::TYPE_INCOME,
                'log' => "提现驳回退款(#{$w->id}): {$reason}",
            ]);

            $w->update([
                'status' => Withdrawal::STATUS_REJECTED,
                'admin_id' => $adminId,
                'reject_reason' => $reason,
                'processed_at' => now(),
            ]);

            return $w;
        });
    }

    /**
     * 用户提现历史。
     */
    public static function history(int $userId): array
    {
        return Withdrawal::where('user_id', $userId)
            ->orderByDesc('id')
            ->limit(50)
            ->get()
            ->toArray();
    }
}
