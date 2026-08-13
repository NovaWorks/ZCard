<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Bill;
use App\Support\BillService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BillController extends Controller
{
    /**
     * 账单列表(带筛选)。
     */
    public function index(Request $request): JsonResponse
    {
        $query = Bill::query()
            ->with(['user:id,username,email', 'order:id,order_no']);

        // 关键字(用户名/说明)
        if ($keyword = $request->input('keyword')) {
            $query->where(function ($q) use ($keyword) {
                $q->where('log', 'like', "%{$keyword}%")
                    ->orWhereHas('user', fn ($u) => $u->where('username', 'like', "%{$keyword}%"));
            });
        }
        // 类型筛选
        if ($request->has('type') && $request->input('type') !== '') {
            $query->where('type', (int) $request->input('type'));
        }
        // 用户筛选
        if ($userId = $request->input('user_id')) {
            $query->where('user_id', $userId);
        }
        // 时间范围
        if ($startDate = $request->input('start_date')) {
            $query->whereDate('created_at', '>=', $startDate);
        }
        if ($endDate = $request->input('end_date')) {
            $query->whereDate('created_at', '<=', $endDate);
        }

        $bills = $query->orderByDesc('id')
            ->paginate($request->integer('pageSize', 15));

        return response()->json($bills);
    }

    /**
     * 统计(总收入/总支出/净额/笔数)。
     */
    public function stats(Request $request): JsonResponse
    {
        $query = Bill::query();
        if ($request->has('type') && $request->input('type') !== '') {
            $query->where('type', (int) $request->input('type'));
        }
        if ($userId = $request->input('user_id')) {
            $query->where('user_id', $userId);
        }
        if ($startDate = $request->input('start_date')) {
            $query->whereDate('created_at', '>=', $startDate);
        }
        if ($endDate = $request->input('end_date')) {
            $query->whereDate('created_at', '<=', $endDate);
        }

        $totalIncome = (int) (clone $query)->where('type', Bill::TYPE_INCOME)->sum('amount');
        $totalExpense = (int) (clone $query)->where('type', Bill::TYPE_EXPENSE)->sum('amount');

        return response()->json([
            'total_income' => $totalIncome,
            'total_expense' => $totalExpense,
            'net_amount' => $totalIncome - $totalExpense,
            'total_count' => (clone $query)->count(),
        ]);
    }

    /**
     * 管理员手动调账。
     */
    public function adjust(Request $request): JsonResponse
    {
        $data = $request->validate([
            'user_id' => 'required|integer|exists:users,id',
            'amount' => 'required|numeric|min:0.01',
            'type' => 'required|integer|in:0,1',
            'log' => 'required|string|max:200',
        ]);

        $amountFen = (int) round($data['amount'] * 100);
        $adminId = $request->user()->id;

        // 安全：禁止给自己调账（防自肥无流水对账），余额调整只能作用于他人账户。
        if ((int) $data['user_id'] === $adminId) {
            return response()->json(['message' => '不允许对本人账户进行调账操作'], 422);
        }

        try {
            $bill = BillService::record(
                $data['user_id'],
                $amountFen,
                $data['type'],
                $data['log'].'(管理员调账)',
                null,
                $adminId,
            );

            return response()->json($bill, 201);
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 400);
        }
    }
}
