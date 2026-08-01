<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Merchant;
use App\Models\SubsiteDomain;
use App\Models\SubsiteLedgerEntry;
use App\Models\SubsiteProductSetting;
use App\Models\Withdrawal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class SubsiteConsoleController extends Controller
{
    /** 我的分站 */
    public function mySubsite(Request $request): JsonResponse
    {
        $merchant = $this->getMySubsite($request);
        if (! $merchant) {
            return response()->json(['message' => '您还没有分站'], 404);
        }
        return response()->json($merchant->load('domains'));
    }

    /** 分站财务概览 */
    public function finance(Request $request): JsonResponse
    {
        $merchant = $this->getMySubsite($request);
        if (! $merchant) return response()->json(['message' => '无分站'], 404);

        $available = SubsiteLedgerEntry::where('merchant_id', $merchant->id)->where('status', 'available')->sum('amount');
        $pending = SubsiteLedgerEntry::where('merchant_id', $merchant->id)->where('status', 'pending')->sum('amount');
        $total = SubsiteLedgerEntry::where('merchant_id', $merchant->id)->whereIn('type', ['order_profit', 'refund_deduct'])->sum('amount');

        return response()->json([
            'total_profit' => (int) $total,
            'available' => (int) $available,
            'pending' => (int) $pending,
        ]);
    }

    /** 利润账本明细 */
    public function ledger(Request $request): JsonResponse
    {
        $merchant = $this->getMySubsite($request);
        if (! $merchant) return response()->json(['message' => '无分站'], 404);
        return response()->json(
            SubsiteLedgerEntry::where('merchant_id', $merchant->id)->orderByDesc('id')->limit(100)->get()
        );
    }

    /** 域名绑定 */
    public function bindDomain(Request $request): JsonResponse
    {
        $merchant = $this->getMySubsite($request);
        if (! $merchant) return response()->json(['message' => '无分站'], 404);
        $data = $request->validate([
            'domain' => 'required|string|max:255',
            'type' => 'required|in:subdomain,custom',
        ]);
        $domain = strtolower(trim($data['domain']));
        $row = SubsiteDomain::create([
            'merchant_id' => $merchant->id,
            'domain' => $domain,
            'type' => $data['type'],
            'verification_token' => $data['type'] === 'custom' ? Str::random(32) : null,
            'verification_status' => $data['type'] === 'subdomain' ? 'verified' : 'pending',
            'status' => $data['type'] === 'subdomain' ? 'active' : 'pending_review',
            'verified_at' => $data['type'] === 'subdomain' ? now() : null,
            'is_primary' => ! SubsiteDomain::where('merchant_id', $merchant->id)->exists(),
        ]);
        return response()->json($row, 201);
    }

    /** 商品配置列表 */
    public function productSettings(Request $request): JsonResponse
    {
        $merchant = $this->getMySubsite($request);
        if (! $merchant) return response()->json(['message' => '无分站'], 404);
        return response()->json(
            SubsiteProductSetting::where('merchant_id', $merchant->id)->with('product:id,name,slug,price')->get()
        );
    }

    /** 商品配置 upsert */
    public function upsertProductSetting(Request $request): JsonResponse
    {
        $merchant = $this->getMySubsite($request);
        if (! $merchant) return response()->json(['message' => '无分站'], 404);
        $data = $request->validate([
            'product_id' => 'required|exists:products,id',
            'is_listed' => 'boolean',
            'pricing_mode' => 'sometimes|in:inherit,markup_percent,fixed_markup,fixed_price',
            'markup_percent' => 'nullable|numeric|min:0',
            'fixed_markup_amount' => 'nullable|integer|min:0',
            'fixed_price_amount' => 'nullable|integer|min:0',
        ]);
        $setting = SubsiteProductSetting::updateOrCreate(
            ['merchant_id' => $merchant->id, 'product_id' => $data['product_id'], 'sku_id' => 0],
            $data
        );
        return response()->json($setting, 201);
    }

    /** 发起提现 */
    public function requestWithdrawal(Request $request): JsonResponse
    {
        $merchant = $this->getMySubsite($request);
        if (! $merchant) return response()->json(['message' => '无分站'], 404);
        $data = $request->validate([
            'amount' => 'required|numeric|min:0.01',
            'method' => 'required|in:alipay,wechat,usdt',
            'account' => 'required|string|max:200',
            'account_name' => 'required|string|max:50',
        ]);
        try {
            $w = \App\Support\SubsiteWithdrawalService::request(
                $merchant->id, (int) round($data['amount'] * 100), $data['method'], $data['account'], $data['account_name']
            );
            return response()->json($w, 201);
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 400);
        }
    }

    /** 更新分站白标配置(站名/logo/公告) — G2 修复 */
    public function updateBranding(Request $request): JsonResponse
    {
        $merchant = $this->getMySubsite($request);
        if (! $merchant) return response()->json(['message' => '无分站'], 404);
        $data = $request->validate([
            'site_name' => 'nullable|string|max:120',
            'logo' => 'nullable|string|max:500',
            'announcement' => 'nullable|string|max:1000',
        ]);
        $settings = $merchant->settings ?? [];
        $settings = array_merge($settings, array_filter($data, fn ($v) => $v !== null));
        $merchant->update(['settings' => $settings]);
        return response()->json($merchant);
    }

    private function getMySubsite(Request $request): ?Merchant
    {
        return Merchant::where('user_id', $request->user()->id)->where('settings->is_subsite', true)->first();
    }
}
