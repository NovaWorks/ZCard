<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Commission;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * 分销佣金后台管理(列表/统计)。spec I3。
 */
class CommissionController extends Controller
{
    /** 佣金统计 */
    public function stats(): JsonResponse
    {
        return response()->json([
            'total_amount' => (int) Commission::sum('amount'),
            'total_count' => Commission::count(),
            'available_amount' => (int) Commission::where('status', 'available')->sum('amount'),
            'pending_amount' => (int) Commission::where('status', 'pending')->sum('amount'),
            'paid_amount' => (int) Commission::where('status', 'paid')->sum('amount'),
        ]);
    }

    /** 佣金列表(带筛选) */
    public function index(Request $request): JsonResponse
    {
        $query = Commission::query()
            ->with(['order:id,order_no,amount', 'buyer:id,username', 'referrer:id,username']);

        if ($referrerId = $request->input('referrer_id')) {
            $query->where('referrer_id', $referrerId);
        }
        if ($orderId = $request->input('order_id')) {
            $query->where('order_id', $orderId);
        }
        if ($request->has('tier') && $request->input('tier') !== '') {
            $query->where('tier', (int) $request->input('tier'));
        }
        if ($status = $request->input('status')) {
            $query->where('status', $status);
        }
        if ($keyword = $request->input('keyword')) {
            $query->where(function ($q) use ($keyword) {
                $q->whereHas('referrer', fn ($u) => $u->where('username', 'like', "%{$keyword}%"))
                  ->orWhereHas('order', fn ($o) => $o->where('order_no', 'like', "%{$keyword}%"));
            });
        }

        $pageSize = (int) ($request->input('page_size', 20));
        $records = $query->orderByDesc('id')->paginate($pageSize);

        return response()->json($records);
    }
}
