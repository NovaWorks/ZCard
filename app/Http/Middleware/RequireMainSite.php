<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/**
 * 分站管理控制台只在主站可访问(spec §8.2,参考 dujiao-next RequireMainTenantForResellerConsole)。
 * 当前请求来自分站域名(subsite 非空)→ 403。
 */
class RequireMainSite
{
    public function handle(Request $request, Closure $next): mixed
    {
        if ($request->attributes->get('subsite')) {
            return response()->json(['message' => '分站管理仅限主站操作'], 403);
        }
        return $next($request);
    }
}
