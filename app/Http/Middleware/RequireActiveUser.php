<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** 拒绝已禁用账号继续使用先前签发的 Sanctum 令牌。 */
class RequireActiveUser
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user) {
            return response()->json(['message' => '未认证'], 401);
        }

        if ((int) $user->status !== 1) {
            $user->currentAccessToken()?->delete();

            return response()->json(['message' => '账号已被禁用'], 403);
        }

        return $next($request);
    }
}
