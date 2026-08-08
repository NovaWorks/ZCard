<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * 给 SPA 入口 HTML(index.html)加 no-cache 响应头。
 *
 * 背景:前端产物用 hash 文件名(可长缓存),但 index.html 若被浏览器/CDN
 * 启发式缓存,更新后仍引用已删除的旧 hash JS → 404 白屏。
 * 故 index.html 必须每次验证(ETag),由 Web 服务器返回最新版。
 *
 * 仅对 HTML 响应生效(不影响 JS/CSS/PNG 等静态资源的缓存)。
 *
 * 注意:web.php 的 /admin 用 response()->file() 返回 BinaryFileResponse,
 * 其 Content-Type 在 prepare() 阶段(中间件之后)才写入,此处读取恒为空字符串,
 * 导致旧实现永远不添加 no-cache 头 → index.html 被启发式缓存 → 更新后
 * 浏览器继续加载旧 chunk(如设置页缺「帮助中心」等新功能)。故需额外判断
 * BinaryFileResponse 的文件类型。
 */
class NoCacheHtml
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        // 仅给 HTML 响应加 no-cache(其余静态资源保持默认缓存策略)
        $contentType = $response->headers->get('Content-Type', '');
        $isHtml = str_contains($contentType, 'text/html');

        if (! $isHtml && $response instanceof BinaryFileResponse) {
            // BinaryFileResponse 的 Content-Type 延迟到 prepare() 才写入,
            // 按文件 MIME/路径判断是否 HTML 入口文件
            $file = $response->getFile();
            $isHtml = str_contains($file->getMimeType() ?? '', 'html')
                || str_ends_with($file->getPathname(), 'index.html');
        }

        if ($isHtml) {
            $response->headers->set('Cache-Control', 'no-cache, no-store, must-revalidate');
            $response->headers->set('Pragma', 'no-cache');
            $response->headers->set('Expires', '0');
        }

        return $response;
    }
}
