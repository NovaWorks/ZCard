<?php

namespace App\Support;

use App\Models\User;
use App\Models\UserGroup;

/**
 * 会员等级自动升级服务。
 *
 * 升级依据由系统设置 member_upgrade_basis 决定:
 * - recharge    按 users.total_recharge(累计充值)
 * - consumption 按 users.total_consumption(累计消费)
 *
 * 升级规则:在 status=true 的等级中,找出阈值 <= 用户累计值的最高档(按 sort 升序取最后一条),
 * 若该档高于当前等级,则升级。只升不降(累计值下降不回退等级)。
 *
 * 金额单位:users 累计字段和 user_groups 阈值字段均为分(integer),直接比较。
 */
class MemberUpgradeService
{
    /**
     * 累加累计充值并按当前依据尝试升级。
     * 在 BillService::record 的 TYPE_INCOME 分支(事务内)调用。
     */
    public static function addRecharge(int $userId, int $amountFen): void
    {
        if ($amountFen <= 0) {
            return;
        }

        $user = User::where('id', $userId)->lockForUpdate()->first();
        if (! $user) {
            return;
        }

        $user->increment('total_recharge', $amountFen);

        if (self::basis() === 'recharge') {
            self::upgrade($user);
        }
    }

    /**
     * 累加累计消费并按当前依据尝试升级。
     * 在 OrderPaid 监听器(事务内)调用。
     */
    public static function addConsumption(int $userId, int $amountFen): void
    {
        if ($amountFen <= 0) {
            return;
        }

        $user = User::where('id', $userId)->lockForUpdate()->first();
        if (! $user) {
            return;
        }

        $user->increment('total_consumption', $amountFen);

        if (self::basis() === 'consumption') {
            self::upgrade($user);
        }
    }

    /**
     * 对指定用户执行升级判定(只升不降)。
     * 阈值字段和累计字段均为分(integer),直接比较无需换算。
     */
    public static function upgrade(User $user): void
    {
        $basis = self::basis();
        $thresholdColumn = $basis === 'consumption' ? 'min_consumption' : 'min_recharge';
        $totalColumn = $basis === 'consumption' ? 'total_consumption' : 'total_recharge';

        // 累计值(分),阈值也是分,直接比较
        $totalFen = (int) $user->{$totalColumn};

        // 在启用等级中找阈值 <= 累计值 的最高档(按 sort 升序,取最后一条)
        $target = UserGroup::where('status', true)
            ->where($thresholdColumn, '<=', $totalFen)
            ->orderBy('sort')
            ->orderBy('id')
            ->get()
            ->last();

        // 没有任何匹配等级(累计值未达最低档),保持现状不降级
        if (! $target) {
            return;
        }

        // 只升不降:目标等级需高于当前等级
        // 等级高低以全量排序位置判定(非 id 大小)
        if ($user->group_id === null) {
            $user->update(['group_id' => $target->id]);
            return;
        }

        // 当前用户已有等级,比较两者在排序中的先后
        $ordered = UserGroup::where('status', true)->orderBy('sort')->orderBy('id')->pluck('id');
        $currentPos = $ordered->search($user->group_id, true);
        $targetPos = $ordered->search($target->id, true);

        // 目标位置靠后才升级(同位置不重复写)
        if ($targetPos !== false && $currentPos !== false && $targetPos > $currentPos) {
            $user->update(['group_id' => $target->id]);
        }
    }

    /** 读取升级依据设置 */
    private static function basis(): string
    {
        return (string) (StorefrontConfig::get('member_upgrade_basis') ?? 'recharge');
    }
}
