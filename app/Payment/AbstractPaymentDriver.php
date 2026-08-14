<?php

namespace App\Payment;

use App\Payment\Contracts\PaymentDriver;

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
     * @param  string  $name  命名路由名(payment.notify / payment.return / payment.cancel)
     * @param  array  $params  路由参数
     * @param  array  $config  通道配置(可含回调域名覆盖)
     */
    protected function namedUrl(string $name, array $params = [], array $config = []): string
    {
        return app(PaymentUrlGenerator::class)->named($name, $params, $config);
    }

    /**
     * 默认敏感凭据键(兼容历史实现)。
     * 各驱动如使用不在该列表中的凭据字段(如 merchant_token),必须覆写本方法,
     * 否则回调会被「凭据未配置」门禁拦截(安全审计 H-3:OkPay/TokenPay 曾因此收款不发货)。
     */
    public function getCredentialKeys(): array
    {
        return ['key', 'secret', 'secret_key', 'private_key', 'public_key',
            'app_secret', 'api_key', 'api_token', 'client_secret', 'webhook_secret', 'mch_secret_key'];
    }
}
