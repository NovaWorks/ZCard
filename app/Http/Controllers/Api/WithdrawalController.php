<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\WithdrawalService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WithdrawalController extends Controller
{
    /**
     * 发起提现(需登录)。
     */
    public function request(Request $request): JsonResponse
    {
        $data = $request->validate([
            'amount' => 'required|numeric|min:0.01',
            'method' => 'required|string|in:alipay,wechat,usdt',
            'account' => 'required|string|max:200',
            'account_name' => 'required|string|max:50',
        ]);

        $amountFen = (int) round($data['amount'] * 100);

        try {
            $w = WithdrawalService::request(
                $request->user()->id,
                $amountFen,
                $data['method'],
                $data['account'],
                $data['account_name'],
            );
            return response()->json($w, 201);
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 400);
        }
    }

    /**
     * 提现历史(需登录)。
     */
    public function history(Request $request): JsonResponse
    {
        return response()->json(WithdrawalService::history($request->user()->id));
    }
}
