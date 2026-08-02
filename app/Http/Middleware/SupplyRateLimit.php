<?php

namespace App\Http\Middleware;

use App\Models\SupplierAccount;
use App\Support\StorefrontConfig;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

/**
 * 供货 API 动态限流中间件(spec §8.5)
 * 每账号每分钟 N 次,N 由 sysadmin 设置页 supply_rate_limit 动态配置(改了立即生效)。
 * 替代原路由启动时读取的 throttle: 配置,解决"改了不生效"问题。
 *
 * key 优先用 api_key(请求头),鉴权后用账号 id,兜底用 IP。
 */
class SupplyRateLimit
{
    public function handle(Request $request, Closure $next): mixed
    {
        $maxAttempts = (int) StorefrontConfig::get('supply_rate_limit');
        $maxAttempts = $maxAttempts > 0 ? $maxAttempts : 60;

        $apiKey = $request->header('X-Supply-Key');
        $account = $request->attributes->get('supplier_account');
        $identifier = $account?->id
            ?? ($apiKey ? 'key:' . $apiKey : 'ip:' . $request->ip());

        // key 格式与 Laravel throttle 一致:具名限流器(每分钟窗口)
        $executed = RateLimiter::attempt(
            key: 'supply:' . $identifier,
            maxAttempts: $maxAttempts,
            callback: fn () => null,
            decaySeconds: 60,
        );

        if (! $executed) {
            return response()->json([
                'ok' => false,
                'error_code' => 'too_many_requests',
                'message' => __('messages.supply_api.too_many_requests'),
            ], 429, [
                'Retry-After' => RateLimiter::availableIn('supply:' . $identifier),
            ]);
        }

        return $next($request);
    }
}
