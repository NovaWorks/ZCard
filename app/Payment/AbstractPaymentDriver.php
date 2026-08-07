<?php

namespace App\Payment;

use App\Payment\Contracts\PaymentDriver;
use Illuminate\Support\Facades\URL;

/**
 * 支付驱动抽象基类。
 *
 * 统一承载各驱动的共享逻辑:
 * - namedUrl():安全构建命名路由 URL,默认生成【绝对地址】(第三方支付平台要求
 *   notify_url / return_url 必须是完整 URL,相对路径会被判「格式不正确」)。
 *   参考 acg-faka:回调地址 = 当前运行域名 + 固定路径(非从 env 读 APP_URL,
 *   而是取当前请求的 scheme+host,部署到哪个域名就自动用哪个域名)。
 */
abstract class AbstractPaymentDriver implements PaymentDriver
{
    /**
     * 安全构建命名路由 URL(绝对地址)。
     *
     * 参考 acg-faka:回调地址 = 当前运行域名 + 固定路径(非从 env 读 APP_URL,
     * 而是取当前请求的 scheme+host,后台部署到哪个域名就自动用哪个域名)。
     *
     * 优先级:config 里的 notify_domain(显式覆盖,如独立公网/内网入口) >
     * 当前请求域名(request 的 scheme+host) > route() 基于 APP_URL > 当前请求 URL 兜底。
     *
     * @param  string  $name   命名路由名(payment.notify / payment.return / payment.cancel)
     * @param  array  $params  路由参数
     * @param  array  $config  通道配置(可含回调域名覆盖)
     */
    protected function namedUrl(string $name, array $params = [], array $config = []): string
    {
        // 1. 显式配置的回调域名(如内网/独立公网入口),参考 acg-faka 的 callback_domain
        $domain = trim((string) ($config['notify_domain'] ?? ''));
        if ($domain !== '') {
            return $this->buildFromDomain(rtrim($domain, '/'), $name, $params);
        }

        // 2. 用当前请求的 scheme+host 生成绝对 URL(acg-faka 同款,非 env)。
        //    当前台/后台部署在 https://kmigo.com 时,回调地址即为 https://kmigo.com/...
        $requestHost = request()->getSchemeAndHttpHost();
        if ($requestHost && app('router')->has($name)) {
            return $this->buildFromDomain($requestHost, $name, $params);
        }

        // 3. 命名路由已定义 → 基于 APP_URL 生成绝对 URL(兜底)
        if (app('router')->has($name)) {
            return url(route($name, $params, false));
        }

        // 4. 路由未定义(测试环境等) → 回退当前请求 URL
        return URL::current();
    }

    /**
     * 用指定域名拼绝对路由 URL(替换 {param} 占位符)。
     */
    protected function buildFromDomain(string $domain, string $name, array $params): string
    {
        $route = app('router')->getRoutes()->getByName($name);
        if (! $route) {
            return $domain;
        }
        $path = $route->uri();
        foreach ($params as $k => $v) {
            $path = str_replace('{'.$k.'}', (string) $v, $path);
        }

        return $domain.'/'.ltrim($path, '/');
    }
}
