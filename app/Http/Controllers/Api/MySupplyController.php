<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SupplierAccount;
use App\Support\StorefrontConfig;
use Illuminate\Database\QueryException;
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
     * 审核制(安全审计 H-2):默认创建后为待审核状态,管理员审核通过前无法调用供货 API;
     * 后台开启 supply_auto_approve(注册即享供货价)时创建即通过。
     * api_secret 明文仅在「首次创建且已审核通过」时返回;待审核/非新建走 /secret 查看。
     */
    public function me(Request $request): JsonResponse
    {
        $user = $request->user();
        $account = SupplierAccount::where('user_id', $user->id)->first();

        $isNew = false;
        if (! $account) {
            $autoApproved = (bool) StorefrontConfig::get('supply_auto_approve');
            try {
                $account = SupplierAccount::create([
                    'user_id' => $user->id,
                    'name' => 'API-'.($user->username ?: $user->email),
                    'api_key' => Str::random(32),
                    'api_secret' => Crypt::encryptString(Str::random(64)),
                    'balance' => 0,
                    'status' => SupplierAccount::STATUS_ACTIVE,
                    'approved' => $autoApproved,
                ]);
            } catch (QueryException) {
                // user_id 唯一索引兜底:并发双击时第二个请求撞唯一键,重查即可
                $account = SupplierAccount::where('user_id', $user->id)->firstOrFail();
            }
            $isNew = true;
        }

        $data = $account->makeVisible(['api_secret'])->toArray();
        // 待审核账号不下发明文密钥(下发了也调不通,避免误以为已可用)
        $data['api_secret'] = ($isNew && $account->approved)
            ? Crypt::decryptString($account->getRawOriginal('api_secret'))
            : '';
        $data['api_secret_masked'] = $this->maskSecret($account);
        $data['is_new'] = $isNew;
        $data['approved'] = $account->approved;
        if (! $account->approved) {
            $data['pending_notice'] = __('messages.supply.pending_approval');
        }

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

        return '••••••••'.substr($plain, -4);
    }
}
