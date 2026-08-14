<?php

namespace App\Http\Middleware;

use App\Models\SupplierAccount;
use App\Supply\HmacSigner;
use App\Supply\NonceStore;
use App\Support\StorefrontConfig;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;

/**
 * 供货 API HMAC 鉴权中间件(spec §4.2)
 * 四头:X-Supply-Key/Timestamp/Nonce/Signature。
 * 流程:查账号→校验状态→timestamp窗口→nonce防重放→验签→注入supplier_account。
 */
class SupplyAuth
{
    public function __construct(private readonly NonceStore $nonceStore) {}

    public function handle(Request $request, Closure $next): mixed
    {
        // 供货总开关 + 作为上游供货开关(supply_supplier_enabled 此前无代码消费,后台关闭形同虚设)
        if (! StorefrontConfig::get('supply_enabled') || ! StorefrontConfig::get('supply_supplier_enabled')) {
            return $this->fail('unauthorized', 401);
        }

        $apiKey = $request->header('X-Supply-Key');
        $timestamp = $request->header('X-Supply-Timestamp');
        $nonce = $request->header('X-Supply-Nonce');
        $signature = $request->header('X-Supply-Signature');

        if (! $apiKey || ! $timestamp || ! $nonce || ! $signature) {
            return $this->fail('unauthorized', 401);
        }

        // 格式约束:防止超长 nonce 制造巨型缓存键/恶意时间戳值。
        if (! preg_match('/^[A-Za-z0-9_-]{1,128}$/', (string) $nonce)) {
            return $this->fail('unauthorized', 401);
        }
        if (! preg_match('/^\d{1,13}$/', (string) $timestamp)) {
            return $this->fail('timestamp_expired', 401);
        }

        $account = SupplierAccount::where('api_key', $apiKey)->first();
        // 审核制(安全审计 H-2):自助开通的账号默认待审核,管理员审核通过前拒绝调用。
        if (! $account || ! $account->isActive() || ! $account->isApproved()) {
            return $this->fail('unauthorized', 401);
        }

        // timestamp 窗口
        $skew = (int) StorefrontConfig::get('supply_timestamp_skew');
        if (! HmacSigner::timestampValid((int) $timestamp, $skew)) {
            return $this->fail('timestamp_expired', 401);
        }

        // 解密 secret(生产加密;测试明文 Crypt::decrypt 失败则当明文用)
        $rawSecret = $account->getRawOriginal('api_secret');
        try {
            $secret = Crypt::decryptString($rawSecret);
        } catch (\Throwable) {
            $secret = $rawSecret; // 未加密(测试或旧数据)
        }

        // 验签:PATH 不含 query;双口径兼容(低危:新客户端签名串追加 query md5 段,
        // 服务端先按旧口径验,失败再按新口径验,升级期互不影响)
        $path = $request->getPathInfo();
        $bodyMd5 = md5($request->getContent() ?: '');
        $signString = HmacSigner::buildSignString($request->method(), $path, $timestamp, $nonce, $bodyMd5);

        if (! HmacSigner::verify($secret, $signString, $signature)) {
            $signStringV2 = HmacSigner::buildSignStringWithQuery(
                $request->method(),
                $path,
                $request->server('QUERY_STRING') ?: '',
                $timestamp,
                $nonce,
                $bodyMd5,
            );
            if (! HmacSigner::verify($secret, $signStringV2, $signature)) {
                return $this->fail('invalid_signature', 401);
            }
        }

        // nonce 防重放(安全审计 L-3):验签通过后才写入,key 绑定 api_key。
        if (! $this->nonceStore->remember($apiKey, $nonce, $skew)) {
            return $this->fail('nonce_reused', 401);
        }

        $request->attributes->set('supplier_account', $account);

        return $next($request);
    }

    private function fail(string $errorCode, int $status): JsonResponse
    {
        return response()->json([
            'ok' => false,
            'error_code' => $errorCode,
            'message' => __('messages.supply_api.'.$errorCode),
        ], $status);
    }
}
