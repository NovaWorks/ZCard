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
                    $allowedHosts = StorefrontConfig::get('service_widget_allowed_hosts');
                    $widgetOrigins = ServiceWidgetScript::allowedOrigins(
                        StorefrontConfig::get('service_widget'),
                        $allowedHosts,
                    );
                } catch (\Throwable) {
                    // 安装前或数据库暂不可用时保持最严格 CSP，不能影响错误页正常返回。
                }
            }

            $externalSources = $widgetOrigins === [] ? '' : ' '.implode(' ', $widgetOrigins);
            // 安全(低危):wss 只放行客服白名单主机的 WebSocket(裸 wss: 允许连任意
            // WS 服务器外发数据,绕过 connect-src 白名单的初衷);Chatwoot/Crisp 的
            // 实时通道与其 SDK 同域名,不受影响。
            $widgetWss = implode(' ', array_map(
                fn (string $origin) => str_replace('https://', 'wss://', $origin),
                $widgetOrigins,
            ));
            $wssSources = $widgetWss === '' ? '' : ' '.$widgetWss;
            $headers->set(
                'Content-Security-Policy',
                "default-src 'self'; script-src 'self'{$externalSources}; style-src 'self' 'unsafe-inline'{$externalSources}; "
                ."img-src 'self' data: blob: https:; font-src 'self' data:{$externalSources}; "
                // 安全审计 M2:连接源收紧为本站 + 客服脚本白名单来源。
                ."connect-src 'self'{$externalSources}{$wssSources}; frame-src 'self'{$externalSources}; "
                ."object-src 'none'; base-uri 'self'; frame-ancestors 'none'",
            );
        }

        if ($request->isSecure()) {
            $headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        return $response;
    }
}
