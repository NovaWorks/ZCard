<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * 管理 API 权限守卫。
 *
 * merchant 目前没有完整的资源级数据隔离,不能访问全局后台 API；
 * 分站主使用 /api/subsite-console/* 自助接口。
 */
class RequireAdminRole
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user) {
            return response()->json(['message' => '未认证'], 401);
        }

        if ((int) $user->status !== 1 || ! $user->hasRole('super_admin')) {
            return response()->json(['message' => '无权限访问管理接口'], 403);
        }

        return $next($request);
    }
}
