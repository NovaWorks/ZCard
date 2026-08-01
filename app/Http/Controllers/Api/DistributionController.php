<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Commission;
use App\Models\User;
use App\Support\StorefrontConfig;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * 推广中心数据(供前台分销中心页)。
 */
class DistributionController extends Controller
{
    /** 我的推广统计 */
    public function stats(Request $request): JsonResponse
    {
        $userId = $request->user()->id;
        $totalCommission = Commission::where('referrer_id', $userId)->sum('amount');
        $availableCommission = Commission::where('referrer_id', $userId)->where('status', 'available')->sum('amount');
        $referralCount = User::where('pid', $userId)->count();

        return response()->json([
            'total_commission' => (int) $totalCommission,
            'available_commission' => (int) $availableCommission,
            'balance' => (int) $request->user()->balance,
            'referral_count' => $referralCount,
            'referral_link' => $this->referralLink($request->user()->username),
        ]);
    }

    /** 我的下级(直推) */
    public function referrals(Request $request): JsonResponse
    {
        $userId = $request->user()->id;
        $referrals = User::where('pid', $userId)
            ->select(['id', 'username', 'created_at'])
            ->orderByDesc('id')
            ->limit(100)
            ->get();

        return response()->json($referrals);
    }

    /** 我的佣金明细 */
    public function commissions(Request $request): JsonResponse
    {
        $userId = $request->user()->id;
        $records = Commission::where('referrer_id', $userId)
            ->with('order:id,order_no,amount', 'buyer:id,username')
            ->orderByDesc('id')
            ->limit(100)
            ->get();

        return response()->json($records);
    }

    private function referralLink(string $username): string
    {
        // 优先用店铺站点 URL(StorefrontConfig.site_url,后台可配);否则回退 app.url
        $base = rtrim((string) (StorefrontConfig::get('site_url') ?: config('app.url', '')), '/');
        return $base . '/?ref=' . urlencode($username);
    }
}
