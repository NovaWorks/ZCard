<?php

namespace App\Http\Middleware;

use App\Support\StorefrontConfig;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * 店铺维护模式中间件。
 * 当 maintenance_mode 开启时,前台 API(非 admin/auth)返回 503 维护状态。
 */
class MaintenanceMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        // 只拦截前台请求,不影响后台管理 API
        $path = $request->path();
        // 放行:后台/认证/验证码/设置读取(前台需要读设置来检测维护状态)
        if (str_starts_with($path, 'api/admin') ||
            str_starts_with($path, 'api/auth') ||
            str_starts_with($path, 'api/captcha') ||
            str_starts_with($path, 'api/settings')) {
            return $next($request);
        }

        if (StorefrontConfig::get('maintenance_mode')) {
            return response()->json([
                'maintenance' => true,
                'message' => StorefrontConfig::get('maintenance_message') ?: '系统维护中,请稍后再来访问。',
            ], 503);
        }

        return $next($request);
    }
}
