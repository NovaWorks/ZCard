<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * 给 SPA 入口 HTML(index.html)加 no-cache 响应头。
 *
 * 背景:前端产物用 hash 文件名(可长缓存),但 index.html 若被浏览器/CDN
 * 启发式缓存,更新后仍引用已删除的旧 hash JS → 404 白屏。
 * 故 index.html 必须每次验证(ETag),由 Web 服务器返回最新版。
 *
 * 仅对 text/html 响应生效(不影响 JS/CSS/PNG 等静态资源的缓存)。
 */
class NoCacheHtml
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        // 仅给 HTML 响应加 no-cache(其余静态资源保持默认缓存策略)
        $contentType = $response->headers->get('Content-Type', '');
        if (str_contains($contentType, 'text/html')) {
            $response->headers->set('Cache-Control', 'no-cache, no-store, must-revalidate');
            $response->headers->set('Pragma', 'no-cache');
            $response->headers->set('Expires', '0');
        }

        return $response;
    }
}
