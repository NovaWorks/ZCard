<?php

namespace App\Http\Middleware;

use App\Support\CurrencyService;
use Closure;
use Illuminate\Http\Request;

/**
 * 解析当前请求的显示货币(spec §3.2):X-Currency 头 > ?currency= > 默认。
 * 写入 request attribute 'currency'。
 */
class ResolveDisplayCurrency
{
    public function handle(Request $request, Closure $next): mixed
    {
        $svc = app(CurrencyService::class);
        $code = $request->header('X-Currency')
            ?: $request->query('currency')
            ?: $svc->getBaseCurrency();
        $code = strtoupper(trim($code));

        // 非启用货币则回退基础货币
        if (! $svc->getCurrency($code)) {
            $code = $svc->getBaseCurrency();
        }
        $request->attributes->set('currency', $code);
        return $next($request);
    }
}
