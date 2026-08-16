<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Cookie\CookieValuePrefix;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

/**
 * 会话认证的 CSRF 守卫(安全审计 H-7)。
 *
 * 背景:api 中间件组为验证码无条件启用了 Session,Sanctum Guard 会优先读取
 * 会话 Cookie;而 ValidateCsrfToken 只存在于 stateful 管道内(SANCTUM_STATEFUL_DOMAINS
 * 命中 referer 才生效)。结果:任何携带有效会话 Cookie 的请求都能以受害者身份调用
 * 全部 API 写操作且无 CSRF 校验,唯一防线是 SameSite=lax——分站与主站同注册域时
 * (subsite_subdomain_base)即被绕过。
 *
 * 本中间件补齐缺口:对「将经由会话 Cookie 认证」的写请求,若其来源(Origin/Referer)
 * 存在且不属于第一方 stateful 域名,则必须携带有效 CSRF token(X-CSRF-TOKEN 或
 * X-XSRF-TOKEN,与 ValidateCsrfToken 同口径)。Bearer 令牌认证与无会话请求不受影响;
 * 无 Origin/Referer 的请求(测试、curl、服务端调用)不受影响——浏览器跨站写请求
 * 必然携带 Origin 头,足以覆盖 CSRF 攻击面。
 */
class VerifyCsrfForSessionAuth
{
    public function handle(Request $request, Closure $next): mixed
    {
        if (! in_array($request->getRealMethod(), ['GET', 'HEAD', 'OPTIONS'], true)) {
            $needsGuard = ! $request->bearerToken()
                && $request->hasSession()
                && auth()->guard('web')->user() !== null
                && $this->hasBrowserOrigin($request)
                && ! $this->fromFirstPartyFrontend($request)
                && ! $this->isSameOriginRequest($request);

            if ($needsGuard && ! $this->tokensMatch($request)) {
                return response()->json(['message' => 'CSRF token mismatch'], 419);
            }
        }

        return $next($request);
    }

    /** 浏览器跨站写请求必带 Origin/Referer;两者皆空视为非浏览器客户端,放行 */
    private function hasBrowserOrigin(Request $request): bool
    {
        return $request->headers->get('origin') !== null
            || $request->headers->get('referer') !== null;
    }

    /**
     * 同源写请求无需 CSRF 校验。
     *
     * Sec-Fetch-Site 头由浏览器控制,跨站攻击者的请求只会带 cross-site/same-site,
     * 无法伪造为 same-origin,因此与 PreventRequestForgery::hasValidOrigin 同口径,
     * 把它作为「第一方同源」的可靠信号放行。这能消除对 SANCTUM_STATEFUL_DOMAINS /
     * APP_URL 配置正确性的依赖(线上域名未配进 stateful 时,同源后台写请求不再被误判)。
     */
    private function isSameOriginRequest(Request $request): bool
    {
        return $request->header('Sec-Fetch-Site') === 'same-origin';
    }

    /** 与 Sanctum EnsureFrontendRequestsAreStateful::fromFrontend 同口径 */
    private function fromFirstPartyFrontend(Request $request): bool
    {
        $domain = $request->headers->get('referer') ?: $request->headers->get('origin');
        if ($domain === null) {
            return false;
        }

        $domain = Str::replaceFirst('https://', '', $domain);
        $domain = Str::replaceFirst('http://', '', $domain);
        $domain = Str::endsWith($domain, '/') ? $domain : "{$domain}/";

        $patterns = collect(array_filter(config('sanctum.stateful', [])))
            ->map(fn ($uri) => trim(
                $uri === Sanctum::$currentRequestHostPlaceholder ? $request->getHttpHost() : $uri
            ).'/*')
            ->all();

        return Str::is($patterns, $domain);
    }

    /** CSRF token 校验:X-CSRF-TOKEN 明文或 X-XSRF-TOKEN(加密 cookie 原样回传) */
    private function tokensMatch(Request $request): bool
    {
        $sessionToken = (string) $request->session()->token();
        if ($sessionToken === '') {
            return false;
        }

        $token = $request->header('X-CSRF-TOKEN');
        if ($token !== null) {
            return hash_equals($sessionToken, (string) $token);
        }

        $xsrf = $request->header('X-XSRF-TOKEN');
        if ($xsrf === null || $xsrf === '') {
            return false;
        }

        // 与 ValidateCsrfToken 同口径:X-XSRF-TOKEN 是加密 XSRF-TOKEN cookie 的回传
        try {
            $decrypted = Crypt::decryptString($xsrf);
            $token = CookieValuePrefix::remove($decrypted);
        } catch (DecryptException) {
            $token = $xsrf; // 兼容 cookie 未加密的部署
        }

        return hash_equals($sessionToken, (string) $token);
    }
}
