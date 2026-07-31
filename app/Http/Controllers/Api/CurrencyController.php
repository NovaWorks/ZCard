<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\CurrencyService;
use Illuminate\Http\JsonResponse;

class CurrencyController extends Controller
{
    /** 启用货币列表(供前台货币切换器) */
    public function index(CurrencyService $svc): JsonResponse
    {
        $base = $svc->getBaseCurrency();
        $list = $svc->getEnabledCurrencies()->map(fn ($c) => [
            'code' => $c->code,
            'name' => $c->name,
            'symbol' => $c->symbol,
            'symbol_position' => $c->symbol_position,
            'decimal_places' => $c->decimal_places,
            'is_base' => $c->is_base,
        ])->values();

        return response()->json([
            'base_currency' => $base,
            'currencies' => $list,
        ]);
    }
}
