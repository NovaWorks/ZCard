<?php

namespace App\Support;

use Illuminate\Support\Facades\Log;

/**
 * 将后台粘贴的第三方客服安装代码编译成可由同源端点加载的 JavaScript。
 *
 * Chatwoot、Crisp 的官方安装代码通常包含完整的 <script> 标签，不能直接作为
 * script.textContent 执行；同时严格 CSP 也会阻止内联代码。本类只负责剥离标签、
 * 安全加载显式的 HTTPS 外部脚本，并提取 CSP 需要放行的来源。
 *
 * 安全约束(2026-08 安全审计 M2):
 * - 外部 script src 仅允许 https 且 host 必须在受信域名白名单内;
 * - 内联脚本体中引用的所有 https 来源同样必须在白名单内,否则整段丢弃;
 * - 白名单可配置(StorefrontConfig: service_widget_allowed_hosts),默认放行
 *   Chatwoot / Crisp 官方域名。目的是防止超管账号被接管后借客服脚本
 *   在商城同源加载任意第三方代码(与 HttpOnly Cookie 会话叠加后,同源脚本
 *   仍可借 CSRF Cookie 操作账户,因此必须收紧远程代码加载面)。
 */
final class ServiceWidgetScript
{
    /** 默认受信客服脚本域名(允许被覆盖)。 */
    public const DEFAULT_ALLOWED_HOSTS = [
        'app.chatwoot.com',
        'cdn.chatwoot.com',
        'client.crisp.chat',
        'settings.crisp.chat',
        'widget.crisp.chat',
    ];

    public static function compile(mixed $widget, mixed $allowedHosts = null): string
    {
        if (! self::isEnabled($widget)) {
            return '';
        }

        $source = trim((string) ($widget['script'] ?? ''));
        if ($source === '') {
            return '';
        }

        $hosts = self::normalizeHosts($allowedHosts);

        // 兼容历史上直接填写纯 JavaScript（不含 <script> 标签）的配置。
        if (! preg_match('/<script\b/i', $source)) {
            if (! self::inlineBodyAllowed($source, $hosts, 'raw-js')) {
                return '';
            }

            return $source."\n";
        }

        preg_match_all('/<script\b([^>]*)>(.*?)<\/script\s*>/is', $source, $matches, PREG_SET_ORDER);
        $compiled = [];

        foreach ($matches as $match) {
            $attributes = (string) ($match[1] ?? '');
            $body = trim((string) ($match[2] ?? ''));
            $src = self::attribute($attributes, 'src');

            if ($src !== null) {
                if (self::isSafeScriptUrl($src, $hosts)) {
                    $compiled[] = self::externalScriptLoader($src, $attributes);
                } else {
                    Log::warning('客服脚本:外部脚本来源不在白名单内,已丢弃', ['src' => $src]);
                }

                // 浏览器会忽略带 src 的 script 标签中的内联内容，保持相同行为。
                continue;
            }

            if ($body !== '') {
                if (self::inlineBodyAllowed($body, $hosts, 'inline')) {
                    $compiled[] = $body;
                }
            }
        }

        return $compiled === [] ? '' : implode("\n;\n", $compiled)."\n";
    }

    /** @return list<string> */
    public static function allowedOrigins(mixed $widget, mixed $allowedHosts = null): array
    {
        if (! self::isEnabled($widget)) {
            return [];
        }

        $hosts = self::normalizeHosts($allowedHosts);
        $source = (string) ($widget['script'] ?? '');
        if ($source === '') {
            return [];
        }

        // 官方代码可能通过变量动态拼接 SDK 地址，因此提取代码中的全部 HTTPS 来源。
        preg_match_all('~https://[^\s\"\'<>\\)]+~i', $source, $matches);
        $origins = [];

        foreach ($matches[0] ?? [] as $url) {
            $parts = parse_url(rtrim((string) $url, '.,;'));
            $host = strtolower((string) ($parts['host'] ?? ''));
            if (strtolower((string) ($parts['scheme'] ?? '')) !== 'https' || $host === '') {
                continue;
            }
            // CSP 只放行白名单内的来源(与 compile() 的丢弃口径一致)。
            if (! self::hostAllowed($host, $hosts)) {
                continue;
            }

            $origin = 'https://'.$host;
            $port = (int) ($parts['port'] ?? 443);
            if ($port !== 443) {
                $origin .= ':'.$port;
            }
            $origins[$origin] = true;
        }

        $result = array_keys($origins);
        sort($result);

        return $result;
    }

    /**
     * 校验内联脚本体引用的外部来源全部在白名单内。
     * 命中违禁来源时记录告警并返回 false(调用方丢弃整段)。
     */
    private static function inlineBodyAllowed(string $body, array $hosts, string $context): bool
    {
        // 内联代码允许引用任意 https 主机(白名单内的),禁止 http 与未知主机。
        preg_match_all('~https?://[^\s\"\'<>\\)]+~i', $body, $matches);

        foreach ($matches[0] ?? [] as $url) {
            $parts = parse_url(rtrim((string) $url, '.,;'));
            $scheme = strtolower((string) ($parts['scheme'] ?? ''));
            $host = strtolower((string) ($parts['host'] ?? ''));

            if ($scheme !== 'https') {
                Log::warning("客服脚本:内联代码({$context})引用非 https 来源,已丢弃", ['url' => $url]);

                return false;
            }
            if (! self::hostAllowed($host, $hosts)) {
                Log::warning("客服脚本:内联代码({$context})引用未授权主机,已丢弃", ['host' => $host]);

                return false;
            }
        }

        return true;
    }

    /** @param  mixed  $allowedHosts  配置值(数组或逗号分隔字符串) */
    private static function normalizeHosts(mixed $allowedHosts): array
    {
        if (is_string($allowedHosts)) {
            $allowedHosts = preg_split('/[\s,]+/', $allowedHosts, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        }
        if (! is_array($allowedHosts) || $allowedHosts === []) {
            $allowedHosts = self::DEFAULT_ALLOWED_HOSTS;
        }

        return array_values(array_unique(array_map(
            fn (mixed $h) => strtolower(trim((string) $h)),
            $allowedHosts,
        )));
    }

    private static function hostAllowed(string $host, array $hosts): bool
    {
        if ($host === '') {
            return false;
        }
        foreach ($hosts as $allowed) {
            if ($host === $allowed || str_ends_with($host, '.'.$allowed)) {
                return true;
            }
        }

        return false;
    }

    private static function isEnabled(mixed $widget): bool
    {
        return is_array($widget)
            && filter_var($widget['enabled'] ?? false, FILTER_VALIDATE_BOOL)
            && trim((string) ($widget['script'] ?? '')) !== '';
    }

    private static function attribute(string $attributes, string $name): ?string
    {
        $quotedName = preg_quote($name, '/');
        if (! preg_match('/\b'.$quotedName.'\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s>]+))/i', $attributes, $match)) {
            return null;
        }

        $value = $match[1] !== '' ? $match[1] : ($match[2] !== '' ? $match[2] : ($match[3] ?? ''));

        return html_entity_decode(trim($value), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    private static function isSafeScriptUrl(string $url, array $hosts): bool
    {
        if (str_starts_with($url, '/') && ! str_starts_with($url, '//')) {
            return true;
        }

        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));

        return $scheme === 'https' && $host !== '' && self::hostAllowed($host, $hosts);
    }

    private static function externalScriptLoader(string $src, string $attributes): string
    {
        $encodedSrc = json_encode($src, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES);
        $async = preg_match('/(?:^|\s)async(?:\s|=|$)/i', $attributes) === 1 ? 'true' : 'false';
        $defer = preg_match('/(?:^|\s)defer(?:\s|=|$)/i', $attributes) === 1 ? 's.defer=true;' : '';
        $type = self::attribute($attributes, 'type');
        $typeLine = $type !== null && in_array(strtolower($type), ['module', 'text/javascript', 'application/javascript'], true)
            ? 's.type='.json_encode($type, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT).';'
            : '';

        return '(function(){var s=document.createElement("script");'
            .'s.src='.$encodedSrc.';s.async='.$async.';'.$defer.$typeLine
            .'(document.head||document.documentElement).appendChild(s);}());';
    }
}
