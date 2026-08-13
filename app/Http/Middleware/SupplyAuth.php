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
        if (! StorefrontConfig::get('supply_enabled')) {
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
        if (! $account || ! $account->isActive()) {
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

        // 验签:PATH 不含 query
        $path = $request->getPathInfo();
        $bodyMd5 = md5($request->getContent() ?: '');
        $signString = HmacSigner::buildSignString($request->method(), $path, $timestamp, $nonce, $bodyMd5);

        if (! HmacSigner::verify($secret, $signString, $signature)) {
            return $this->fail('invalid_signature', 401);
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
