<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Currency;
use App\Support\CurrencyService;
use App\Support\StorefrontConfig;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CurrencyController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(Currency::orderBy('sort')->get());
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'code' => 'required|string|size:3|unique:currencies,code',
            'name' => 'required|string|max:40',
            'symbol' => 'required|string|max:10',
            'symbol_position' => ['required', Rule::in(['before', 'after'])],
            'decimal_places' => 'required|integer|min:0|max:4',
            'exchange_rate' => 'required|numeric|gt:0',
            'is_base' => 'boolean',
            'is_enabled' => 'boolean',
            'sort' => 'integer',
        ]);
        $data['code'] = strtoupper($data['code']);
        // 新建即设为基础货币时,同步取消其他基础 + StorefrontConfig(见 I-2)
        if (! empty($data['is_base'])) {
            Currency::where('is_base', true)->update(['is_base' => false]);
            $data['exchange_rate'] = 1;
        }
        $cur = Currency::create($data);
        if (! empty($data['is_base'])) {
            StorefrontConfig::setMany(['base_currency' => $cur->code]);
        }
        app(CurrencyService::class)->flushCache();
        return response()->json($cur, 201);
    }

    public function update(Request $request, string $code): JsonResponse
    {
        $cur = Currency::findOrFail(strtoupper($code));
        $data = $request->validate([
            'name' => 'sometimes|string|max:40',
            'symbol' => 'sometimes|string|max:10',
            'symbol_position' => ['sometimes', Rule::in(['before', 'after'])],
            'decimal_places' => 'sometimes|integer|min:0|max:4',
            'exchange_rate' => 'sometimes|numeric|gt:0',
            'is_base' => 'sometimes|boolean',
            'is_enabled' => 'sometimes|boolean',
            'sort' => 'sometimes|integer',
        ]);
        // 设基础货币时,其余取消基础,且汇率强制为 1,同步 StorefrontConfig.base_currency
        // (保持 currencies.is_base 与 settings.base_currency 两个来源一致,见 I-2)
        if (! empty($data['is_base'])) {
            Currency::where('is_base', true)->where('code', '!=', $cur->code)->update(['is_base' => false]);
            $data['exchange_rate'] = 1;
            StorefrontConfig::setMany(['base_currency' => $cur->code]);
        }
        $cur->update($data);
        app(CurrencyService::class)->flushCache();
        return response()->json($cur->fresh());
    }

    public function destroy(string $code): JsonResponse
    {
        $cur = Currency::findOrFail(strtoupper($code));
        abort_if($cur->is_base, 422, __('messages.currency_base_undeletable'));
        $cur->delete();
        app(CurrencyService::class)->flushCache();
        return response()->json(null, 204);
    }
}
