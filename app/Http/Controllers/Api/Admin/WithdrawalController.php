<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Withdrawal;
use App\Support\WithdrawalService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WithdrawalController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Withdrawal::query()->with('user:id,username,email');

        if ($keyword = $request->input('keyword')) {
            $query->where(function ($q) use ($keyword) {
                $q->where('account', 'like', "%{$keyword}%")
                  ->orWhereHas('user', fn ($u) => $u->where('username', 'like', "%{$keyword}%"));
            });
        }
        if ($request->has('status') && $request->input('status') !== '') {
            $query->where('status', $request->input('status'));
        }
        if ($request->has('method') && $request->input('method') !== '') {
            $query->where('method', $request->input('method'));
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

        $list = $query->orderByDesc('id')->paginate($request->integer('pageSize', 15));
        return response()->json($list);
    }

    public function stats(Request $request): JsonResponse
    {
        $query = Withdrawal::query();
        if ($request->has('status') && $request->input('status') !== '') {
            $query->where('status', $request->input('status'));
        }

        return response()->json([
            'pending_count' => (clone $query)->where('status', 'pending')->count(),
            'approved_count' => (clone $query)->where('status', 'approved')->count(),
            'rejected_count' => (clone $query)->where('status', 'rejected')->count(),
            'pending_amount' => (int) (clone $query)->where('status', 'pending')->sum('amount'),
            'approved_amount' => (int) (clone $query)->where('status', 'approved')->sum('actual_amount'),
            'total_count' => (clone $query)->count(),
        ]);
    }

    public function approve(Request $request, int $id): JsonResponse
    {
        try {
            $w = WithdrawalService::approve($id, $request->user()->id);
            return response()->json($w);
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 400);
        }
    }

    public function reject(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'reason' => 'required|string|max:200',
        ]);
        try {
            $w = WithdrawalService::reject($id, $request->user()->id, $data['reason']);
            return response()->json($w);
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 400);
        }
    }
}
