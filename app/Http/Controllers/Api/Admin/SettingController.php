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

        // 卡密加密密钥:敏感字段,存 Crypt 密文(不回显明文)
        if (isset($kv['card_encryption_key'])) {
            if ($kv['card_encryption_key'] === '' || $kv['card_encryption_key'] === null) {
                unset($kv['card_encryption_key']); // 留空=保持原值
            } else {
                $kv['card_encryption_key'] = \Illuminate\Support\Facades\Crypt::encryptString((string) $kv['card_encryption_key']);
            }
        }

        StorefrontConfig::setMany($kv);

        // 回显时脱敏密钥
        $all = StorefrontConfig::all();
        if (! empty($all['card_encryption_key'])) {
            $all['card_encryption_key'] = '••••••••';
        }

        return response()->json($all);
    }
}
