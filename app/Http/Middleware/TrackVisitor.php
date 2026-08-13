<?php

namespace App\Http\Middleware;

use App\Models\VisitLog;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

/**
 * 前台流量埋点(数据看板 PV/UV,issue #6)。
 *
 * 规则:
 * - 仅统计前台页面(排除 /api /admin /filament /install /up 等非访客路径);
 * - 跳过搜索引擎爬虫/监控探针 UA;
 * - 同一 IP 60 秒内去重(防高频刷新刷爆表,PV 精度足够且不影响 UV)。
 */
class TrackVisitor
{
    /** 同 IP 去重窗口(秒) */
    private const DEDUP_SECONDS = 60;

    /** 非访客路径前缀(不统计) */
    private const EXCLUDE_PREFIXES = [
        '/api/', '/admin', '/filament', '/install', '/up', '/.env', '/vendor',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        // 只统计前台页面 GET 请求
        if (! $request->isMethod('GET')) {
            return $next($request);
        }

        $path = '/'.ltrim($request->path(), '/');
        foreach (self::EXCLUDE_PREFIXES as $prefix) {
            if (str_starts_with($path, $prefix)) {
                return $next($request);
            }
        }

        $ua = (string) $request->userAgent();
        if ($this->isBot($ua)) {
            return $next($request);
        }

        $ip = $request->ip();
        // 同 IP 窗口内去重:命中说明刚刚已记录
        $dedupKey = 'visit:dedup:'.$ip.':'.md5($path);
        if (Cache::has($dedupKey)) {
            return $next($request);
        }

        try {
            VisitLog::create([
                'ip' => $ip,
                'user_agent' => mb_substr($ua, 0, 500),
                'path' => mb_substr($path, 0, 500),
            ]);
            Cache::put($dedupKey, 1, self::DEDUP_SECONDS);
        } catch (\Throwable $e) {
            // 流量统计失败不能影响页面
            report($e);
        }

        return $next($request);
    }

    private function isBot(string $ua): bool
    {
        if ($ua === '') {
            return false;
        }

        return (bool) preg_match(
            '/bot|crawl|spider|slurp|bingpreview|facebookexternalhit|whatsapp|telegrambot|'
            .'curl|wget|python-requests|go-http-client|headless|monitor|uptime|pingdom/i',
            $ua
        );
    }
}
