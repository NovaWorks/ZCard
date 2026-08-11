<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * 首次登录强制改密（spec §7.3）。
 * super_admin 登录后若 password_changed_at 为 null → 跳改密页。
 * Filament 登录后访问非改密页时拦截。
 */
class ForcePasswordChange
{
    public function handle(Request $request, Closure $next): Response
    {
        /** @var User $user */
        $user = $request->user();
        if (! $user) {
            return $next($request);
        }

        // 只约束后台角色
        if (! $user->hasRole('super_admin')) {
            return $next($request);
        }

        // 已改密，放行
        if ($user->password_changed_at !== null) {
            return $next($request);
        }

        // 当前已在改密相关路由，放行避免死循环
        if ($request->routeIs('filament.admin.pages.profile') ||
            $request->is('*/profile*') ||
            $request->is('logout*')) {
            return $next($request);
        }

        // 跳到 Filament 个人资料页（含改密）。Phase 0 用 profile 页承载改密。
        return redirect()->route('filament.admin.pages.profile');
    }
}
