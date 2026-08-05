<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SupplierAccount;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;

/**
 * 用户自助供货对接(spec: 个人中心 API 对接)。
 * 登录用户在个人中心自助获取/查看供货 API 凭证 + 余额,充值后复制凭证即可对接本站供货 API。
 */
class MySupplyController extends Controller
{
    /**
     * GET /api/supplier-account/me
     * 获取当前用户的供货账号;没有则自动创建一个(api_key/api_secret 随机生成)。
     * api_secret 仅首次创建时返回明文,之后返回脱敏值(需单独调 secret 查看)。
     */
    public function me(Request $request): JsonResponse
    {
        $user = $request->user();
        $account = SupplierAccount::where('user_id', $user->id)->first();

        $isNew = false;
        if (! $account) {
            $account = SupplierAccount::create([
                'user_id' => $user->id,
                'name' => 'API-' . ($user->username ?: $user->email),
                'api_key' => Str::random(32),
                'api_secret' => Crypt::encryptString(Str::random(64)),
                'balance' => 0,
                'status' => SupplierAccount::STATUS_ACTIVE,
            ]);
            $isNew = true;
        }

        $data = $account->makeVisible(['api_secret'])->toArray();
        $data['api_secret'] = $isNew
            ? Crypt::decryptString($account->getRawOriginal('api_secret'))
            : '';
        $data['api_secret_masked'] = $this->maskSecret($account);
        $data['is_new'] = $isNew;

        return response()->json($data);
    }

    /**
     * POST /api/supplier-account/regenerate
     * 重置 api_secret(旧密钥立即失效)。
     */
    public function regenerate(Request $request): JsonResponse
    {
        $account = SupplierAccount::where('user_id', $request->user()->id)->firstOrFail();
        $plainSecret = Str::random(64);
        $account->update(['api_secret' => Crypt::encryptString($plainSecret)]);

        return response()->json([
            'api_secret' => $plainSecret,
            'warning' => __('messages.supply.secret_show_once_warning'),
        ]);
    }

    /**
     * GET /api/supplier-account/secret
     * 查看当前供货账号的 api_secret 明文(供复制对接)。
     */
    public function showSecret(Request $request): JsonResponse
    {
        $account = SupplierAccount::where('user_id', $request->user()->id)->firstOrFail();

        return response()->json([
            'api_secret' => Crypt::decryptString($account->getRawOriginal('api_secret')),
        ]);
    }

    private function maskSecret(SupplierAccount $account): string
    {
        try {
            $plain = Crypt::decryptString($account->getRawOriginal('api_secret'));
        } catch (\Throwable) {
            $plain = $account->getRawOriginal('api_secret');
        }

        return '••••••••' . substr($plain, -4);
    }
}
