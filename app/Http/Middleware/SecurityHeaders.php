<?php

namespace App\Http\Middleware;

use App\Support\ServiceWidgetScript;
use App\Support\StorefrontConfig;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $headers = $response->headers;

        $headers->set('X-Content-Type-Options', 'nosniff');
        $headers->set('X-Frame-Options', 'DENY');
        $headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');
        // Filament/Livewire 自带运行时依赖动态脚本；其页面保留其他安全头，
        // 商城与正式后台 SPA 使用严格 CSP。
        if (! $request->is('filament', 'filament/*')) {
            $widgetOrigins = [];
            if (! $request->is('api/*', 'admin', 'admin/*', 'install', 'install/*')) {
                try {
                    $widgetOrigins = ServiceWidgetScript::allowedOrigins(StorefrontConfig::get('service_widget'));
                } catch (\Throwable) {
                    // 安装前或数据库暂不可用时保持最严格 CSP，不能影响错误页正常返回。
                }
            }

            $externalSources = $widgetOrigins === [] ? '' : ' '.implode(' ', $widgetOrigins);
            $headers->set(
                'Content-Security-Policy',
                "default-src 'self'; script-src 'self'{$externalSources}; style-src 'self' 'unsafe-inline'{$externalSources}; "
                ."img-src 'self' data: blob: https:; font-src 'self' data:{$externalSources}; "
                ."connect-src 'self' https: wss:; frame-src 'self'{$externalSources}; "
                ."object-src 'none'; base-uri 'self'; frame-ancestors 'none'",
            );
        }

        if ($request->isSecure()) {
            $headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        return $response;
    }
}
