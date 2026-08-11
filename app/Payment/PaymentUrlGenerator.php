<?php

namespace App\Payment;

use App\Support\StorefrontConfig;

/**
 * 支付跳转与通知 URL 的唯一生成入口。
 *
 * 优先级：历史通道 notify_domain > 系统支付回调域名 > 当前公网请求域名
 * > 店铺 site_url > APP_URL 路由兜底。
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

        $globalDomain = $this->origin($this->setting('payment_callback_domain'));
        if ($globalDomain !== null && $path !== null) {
            return $globalDomain.'/'.ltrim($path, '/');
        }

        if (app()->bound('request') && $path !== null) {
            $requestDomain = $this->origin((string) request()->getSchemeAndHttpHost());
            $siteDomain = $this->origin($this->setting('site_url'));

            // 反向代理未传递 HTTPS scheme 时,优先使用同一主机的 site_url 修正协议。
            if ($requestDomain !== null && $siteDomain !== null
                && parse_url($requestDomain, PHP_URL_HOST) === parse_url($siteDomain, PHP_URL_HOST)) {
                return $siteDomain.'/'.ltrim($path, '/');
            }
            if ($requestDomain !== null && ! $this->isLocalOrigin($requestDomain)) {
                return $requestDomain.'/'.ltrim($path, '/');
            }
            if ($siteDomain !== null) {
                return $siteDomain.'/'.ltrim($path, '/');
            }
        }

        if ($path !== null) {
            $siteDomain = $this->origin($this->setting('site_url'));
            if ($siteDomain !== null) {
                return $siteDomain.'/'.ltrim($path, '/');
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

    private function origin(string $url): ?string
    {
        $url = rtrim(trim($url), '/');
        if ($url === '' || filter_var($url, FILTER_VALIDATE_URL) === false) {
            return null;
        }
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        $host = (string) parse_url($url, PHP_URL_HOST);
        if (! in_array($scheme, ['http', 'https'], true) || $host === '') {
            return null;
        }
        $port = parse_url($url, PHP_URL_PORT);

        return $scheme.'://'.$host.($port ? ':'.$port : '');
    }

    private function isLocalOrigin(string $origin): bool
    {
        $host = strtolower((string) parse_url($origin, PHP_URL_HOST));
        if ($host === 'localhost' || str_ends_with($host, '.localhost')) {
            return true;
        }

        return filter_var($host, FILTER_VALIDATE_IP) !== false
            && filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
    }

    /** 安装前或数据库短暂不可用时不影响 URL 的基础兜底生成。 */
    private function setting(string $key): string
    {
        try {
            return (string) StorefrontConfig::get($key);
        } catch (\Throwable) {
            return '';
        }
    }
}
