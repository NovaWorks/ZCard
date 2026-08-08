<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Recharge;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * 后台充值单管理。
 * 列表(含统计) + 详情。充值单状态:pending / paid / closed。
 */
class RechargeController extends Controller
{
    /**
     * 构建筛选查询(供 index/stats 复用)。
     */
    protected function buildQuery(Request $request)
    {
        $query = Recharge::query()
            ->with('user:id,username,email');

        // 关键字(充值单号 / 用户名 / 邮箱)
        if ($keyword = $request->input('keyword')) {
            $query->where(function ($q) use ($keyword) {
                $q->where('recharge_no', 'like', "%{$keyword}%")
                    ->orWhereHas('user', fn ($u) => $u->where('username', 'like', "%{$keyword}%")
                        ->orWhere('email', 'like', "%{$keyword}%"));
            });
        }

        // 精确筛选
        if ($status = $request->input('status')) {
            $query->where('status', $status);
        }
        if ($target = $request->input('target')) {
            $query->where('target', $target);
        }

        // 时间范围
        if ($startDate = $request->input('start_date')) {
            $query->whereDate('created_at', '>=', $startDate);
        }
        if ($endDate = $request->input('end_date')) {
            $query->whereDate('created_at', '<=', $endDate);
        }

        return $query;
    }

    /**
     * 充值单列表(分页)。
     */
    public function index(Request $request): JsonResponse
    {
        $query = $this->buildQuery($request);
        $recharges = $query->orderByDesc('id')
            ->paginate($request->integer('pageSize', 15));

        return response()->json($recharges);
    }

    /**
     * 统计卡片数据(基于当前筛选条件)。
     */
    public function stats(Request $request): JsonResponse
    {
        $baseQuery = $this->buildQuery($request);

        return response()->json([
            'total_count' => (clone $baseQuery)->count(),
            'total_amount' => (clone $baseQuery)->sum('amount'),
            'pending_amount' => (clone $baseQuery)->where('status', Recharge::STATUS_PENDING)->sum('amount'),
            'paid_amount' => (clone $baseQuery)->where('status', Recharge::STATUS_PAID)->sum('amount'),
            'closed_amount' => (clone $baseQuery)->where('status', Recharge::STATUS_CLOSED)->sum('amount'),
        ]);
    }

    /**
     * 充值单详情(含用户信息)。
     */
    public function show(int $id): JsonResponse
    {
        $recharge = Recharge::with('user:id,username,email')
            ->findOrFail($id);

        return response()->json($recharge);
    }
}
