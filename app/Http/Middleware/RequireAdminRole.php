<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * 管理 API 权限守卫(P0 安全修复)。
 * 要求当前用户拥有 super_admin 或 merchant 角色,普通 user 角色拒绝(403)。
 * 这补上了 /api/admin/* 路由组仅有 auth:sanctum 而无角色检查的安全漏洞。
 */
class RequireAdminRole
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user) {
            return response()->json(['message' => '未认证'], 401);
        }

        if (! $user->hasRole(['super_admin', 'merchant'])) {
            return response()->json(['message' => '无权限访问管理接口'], 403);
        }

        return $next($request);
    }
}
