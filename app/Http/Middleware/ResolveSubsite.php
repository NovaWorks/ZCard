<?php

namespace App\Http\Middleware;

use App\Models\Merchant;
use App\Models\SubsiteDomain;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * 分站域名解析(spec §3):归一化 Host → Redis 缓存查 subsite_domains → 存 request attribute。
 * null=主站。功能未开(ZCARD_SUB_SITE=false)直接放行。
 */
class ResolveSubsite
{
    public function handle(Request $request, Closure $next): mixed
    {
        if (! config('zcard.features.sub_site')) {
            $request->attributes->set('subsite', null);
            return $next($request);
        }

        $host = $this->normalizeHost($request->host());
        $merchant = null;

        if ($host) {
            // 缓存 merchant_id 标量而非 Eloquent 对象:database cache 存 PHP serialize
            // 的二进制(Eloquent 对象含 \0 字节)会被 MySQL 存坏成 __PHP_Incomplete_Class。
            $cached = Cache::remember("subsite:domain:{$host}", 300, function () use ($host) {
                $domain = SubsiteDomain::where('domain', $host)
                    ->where('status', 'active')
                    ->where('verification_status', 'verified')
                    ->first();

                return $domain ? (int) $domain->merchant_id : false;
            });
            $merchant = $cached ? Merchant::find($cached) : null;
        }

        $request->attributes->set('subsite', $merchant);
        return $next($request);
    }

    /**
     * 归一化:lowercase + 剥离端口 + 剥离 www + 剥离尾点 + punycode。
     */
    private function normalizeHost(string $host): string
    {
        $host = strtolower(trim($host));
        $host = preg_replace('/:\d+$/', '', $host);
        $host = preg_replace('/^www\./', '', $host);
        $host = rtrim($host, '.');
        if (function_exists('idn_to_ascii')) {
            $converted = @idn_to_ascii($host);
            if ($converted) {
                $host = $converted;
            }
        }
        return $host;
    }
}
