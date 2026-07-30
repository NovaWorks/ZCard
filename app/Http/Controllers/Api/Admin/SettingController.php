<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Support\StorefrontConfig;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * 店铺外观配置读写(spec §3.3)。代理 StorefrontConfig 静态门面。
 */
class SettingController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(StorefrontConfig::all());
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'settings' => 'sometimes|array',
            'settings.*' => 'nullable',
        ]);

        // 兼容直接传 key-value 或包成 settings
        $kv = $data['settings'] ?? $request->except('settings');
        StorefrontConfig::setMany($kv);

        return response()->json(StorefrontConfig::all());
    }
}
