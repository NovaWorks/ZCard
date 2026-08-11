<?php

namespace App\Payment;

/**
 * 支付跳转与通知 URL 的唯一生成入口。
 *
 * 优先级：通道 notify_domain > 当前请求域名 > APP_URL 路由兜底。
 * 后台展示和支付驱动必须共用本类，避免展示地址与实际提交地址不一致。
 */
class PaymentUrlGenerator
{
    public function named(string $name, array $params = [], array $config = []): string
    {
        $path = $this->routePath($name, $params);
        $configuredDomain = rtrim(trim((string) ($config['notify_domain'] ?? '')), '/');

        if ($configuredDomain !== '' && $path !== null) {
            return $configuredDomain.'/'.ltrim($path, '/');
        }

        if (app()->bound('request') && $path !== null) {
            $requestDomain = rtrim((string) request()->getSchemeAndHttpHost(), '/');
            if ($requestDomain !== '') {
                return $requestDomain.'/'.ltrim($path, '/');
            }
        }

        if ($path !== null) {
            return url($path);
        }

        return app()->bound('request') ? request()->url() : rtrim((string) config('app.url'), '/');
    }

    private function routePath(string $name, array $params): ?string
    {
        if (! app('router')->has($name)) {
            return null;
        }

        return route($name, $params, false);
    }
}
