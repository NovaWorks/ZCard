<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;

/**
 * 解析 Accept-Language / X-Lang 设 App locale(spec §4.2)。
 * zh* → zh_CN;en → en;默认 zh_CN。
 */
class SetLocale
{
    public function handle(Request $request, Closure $next): mixed
    {
        $header = $request->header('Accept-Language', '');
        $locale = 'zh_CN';
        // 浏览器语言含 en 且不含 zh → en
        if (stripos($header, 'zh') === false && stripos($header, 'en') !== false) {
            $locale = 'en';
        }
        // X-Lang 头优先(供前端显式传)
        if ($lang = $request->header('X-Lang')) {
            $locale = strtolower($lang) === 'en' ? 'en' : 'zh_CN';
        }
        App::setLocale($locale);
        return $next($request);
    }
}
