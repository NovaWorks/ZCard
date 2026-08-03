<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Recharge;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * 用户余额充值(需登录)。
 *
 * 流程:创建充值单(RCH,pending) → 前端调 /payments/create 发起支付 →
 * 第三方异步回调 → PaymentService::handleRechargeCallback 入账(BillService)。
 */
class RechargeController extends Controller
{
    /**
     * 创建充值单。amount 单位为元(前端传),转成分存储。
     */
    public function create(Request $request): JsonResponse
    {
        $data = $request->validate([
            'amount' => 'required|numeric|min:0.01',
        ]);

        // 金额限制:下限 0.01 元,上限可后台配置(默认 50000 元),防止异常金额刷会员等级/套现
        $amountYuan = (float) $data['amount'];
        $maxYuan = (float) (\App\Support\StorefrontConfig::get('recharge_max_amount') ?? 50000);
        if ($amountYuan < 0.01 || $amountYuan > $maxYuan) {
            return response()->json(['message' => __('messages.recharge.amount_invalid')], 422);
        }
        $amountFen = (int) round($amountYuan * 100);

        $recharge = Recharge::create([
            'recharge_no' => $this->generateRechargeNo(),
            'user_id' => $request->user()->id,
            'amount' => $amountFen,
            'status' => Recharge::STATUS_PENDING,
        ]);

        return response()->json([
            'recharge_no' => $recharge->recharge_no,
            'amount' => $recharge->amount,
            'status' => $recharge->status,
        ], 201);
    }

    /**
     * 充值历史(当前用户)。
     */
    public function history(Request $request): JsonResponse
    {
        $list = Recharge::where('user_id', $request->user()->id)
            ->orderByDesc('id')
            ->limit(50)
            ->get();

        return response()->json($list->map(fn ($r) => [
            'id' => $r->id,
            'recharge_no' => $r->recharge_no,
            'amount' => $r->amount,
            'status' => $r->status,
            'created_at' => $r->created_at?->toDateTimeString(),
            'paid_at' => $r->paid_at?->toDateTimeString(),
        ]));
    }

    /**
     * 查询充值单状态(支付页轮询用)。
     */
    public function status(string $rechargeNo, Request $request): JsonResponse
    {
        $r = Recharge::where('recharge_no', $rechargeNo)
            ->where('user_id', $request->user()->id)
            ->first();
        if (! $r) {
            return response()->json(['message' => __('messages.recharge.not_found')], 404);
        }
        return response()->json([
            'recharge_no' => $r->recharge_no,
            'amount' => $r->amount,
            'status' => $r->status,
        ]);
    }

    private function generateRechargeNo(): string
    {
        return 'RCH' . now()->format('YmdHis') . strtoupper(Str::random(6));
    }
}
