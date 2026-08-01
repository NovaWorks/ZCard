<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Merchant;
use App\Models\SubsiteDomain;
use App\Models\SubsiteProductSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SubsiteController extends Controller
{
    /** 分站列表(merchant where settings->is_subsite=true) */
    public function index(): JsonResponse
    {
        $subsites = Merchant::where('settings->is_subsite', true)
            ->with(['owner:id,username', 'domains'])
            ->orderByDesc('id')
            ->paginate(20);
        return response()->json($subsites);
    }

    /** 创建分站 */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'user_id' => 'required|exists:users,id',
            'name' => 'required|string|max:100',
            'slug' => 'required|string|max:100|unique:merchants,slug',
            'default_markup_percent' => 'nullable|numeric|min:0',
            'max_markup_percent' => 'nullable|numeric|min:0',
            'commission_rate' => 'nullable|numeric|min:0|max:100',
        ]);
        $merchant = Merchant::create([
            'user_id' => $data['user_id'],
            'name' => $data['name'],
            'slug' => $data['slug'],
            'status' => 1,
            'commission_rate' => $data['commission_rate'] ?? 0,
            'settings' => [
                'is_subsite' => true,
                'default_markup_percent' => $data['default_markup_percent'] ?? 0,
                'max_markup_percent' => $data['max_markup_percent'] ?? 50,
                'settlement_confirm_days' => 7,
            ],
        ]);
        return response()->json($merchant, 201);
    }

    /** 域名审批 */
    public function updateDomain(Request $request, SubsiteDomain $domain): JsonResponse
    {
        $data = $request->validate([
            'status' => ['required', Rule::in(['pending_review', 'active', 'disabled'])],
            'verification_status' => ['sometimes', Rule::in(['pending', 'verified', 'failed'])],
        ]);
        $domain->update($data);
        \Illuminate\Support\Facades\Cache::forget("subsite:domain:{$domain->domain}");
        return response()->json($domain);
    }

    /** 分站商品配置列表 */
    public function productSettings(Merchant $merchant): JsonResponse
    {
        $settings = SubsiteProductSetting::where('merchant_id', $merchant->id)
            ->with('product:id,name,slug,price')
            ->orderByDesc('id')
            ->paginate(50);
        return response()->json($settings);
    }

    /** 保存/更新分站商品配置(upsert) */
    public function upsertProductSetting(Request $request): JsonResponse
    {
        $data = $request->validate([
            'merchant_id' => 'required|exists:merchants,id',
            'product_id' => 'required|exists:products,id',
            'sku_id' => 'nullable|integer|min:0',
            'is_listed' => 'boolean',
            'pricing_mode' => ['sometimes', Rule::in(['inherit', 'markup_percent', 'fixed_markup', 'fixed_price'])],
            'markup_percent' => 'nullable|numeric|min:0',
            'fixed_markup_amount' => 'nullable|integer|min:0',
            'fixed_price_amount' => 'nullable|integer|min:0',
        ]);
        $data['sku_id'] = $data['sku_id'] ?? 0;
        $setting = SubsiteProductSetting::updateOrCreate(
            ['merchant_id' => $data['merchant_id'], 'product_id' => $data['product_id'], 'sku_id' => $data['sku_id']],
            $data
        );
        return response()->json($setting, 201);
    }
}
