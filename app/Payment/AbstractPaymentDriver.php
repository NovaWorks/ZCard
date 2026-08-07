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
 *   参考 acg-faka:回调地址 = 回调域名 + 固定路径,回调域名可后台单独配置,
 *   为空时回退 APP_URL / 当前请求域名。
 */
abstract class AbstractPaymentDriver implements PaymentDriver
{
    /**
     * 安全构建命名路由 URL(绝对地址)。
     *
     * 优先级:config 里的 notify_domain/回调域名(如 https://pay.example.com) >
     * route() 基于 APP_URL 生成的绝对 URL > 当前请求 URL 兜底。
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
            $domain = rtrim($domain, '/');
            if (app('router')->has($name)) {
                $path = app('router')->getRoutes()->getByName($name)->uri();
                // 把 {channel} 等参数替换为实际值,并拼上 query 参数
                foreach ($params as $k => $v) {
                    $path = str_replace('{'.$k.'}', (string) $v, $path);
                }
                return $domain.'/'.ltrim($path, '/');
            }
        }

        // 2. 命名路由已定义 → 基于 APP_URL 生成绝对 URL
        if (app('router')->has($name)) {
            return url(route($name, $params, false));
        }

        // 3. 路由未定义(测试环境等) → 回退当前请求 URL
        return URL::current();
    }
}
