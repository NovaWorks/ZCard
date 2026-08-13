<?php

namespace App\Support;

/**
 * 将后台粘贴的第三方客服安装代码编译成可由同源端点加载的 JavaScript。
 *
 * Chatwoot、Crisp 的官方安装代码通常包含完整的 <script> 标签，不能直接作为
 * script.textContent 执行；同时严格 CSP 也会阻止内联代码。本类只负责剥离标签、
 * 安全加载显式的 HTTPS 外部脚本，并提取 CSP 需要放行的来源。
 */
final class ServiceWidgetScript
{
    public static function compile(mixed $widget): string
    {
        if (! self::isEnabled($widget)) {
            return '';
        }

        $source = trim((string) ($widget['script'] ?? ''));
        if ($source === '') {
            return '';
        }

        // 兼容历史上直接填写纯 JavaScript（不含 <script> 标签）的配置。
        if (! preg_match('/<script\b/i', $source)) {
            return $source."\n";
        }

        preg_match_all('/<script\b([^>]*)>(.*?)<\/script\s*>/is', $source, $matches, PREG_SET_ORDER);
        $compiled = [];

        foreach ($matches as $match) {
            $attributes = (string) ($match[1] ?? '');
            $body = trim((string) ($match[2] ?? ''));
            $src = self::attribute($attributes, 'src');

            if ($src !== null) {
                if (self::isSafeScriptUrl($src)) {
                    $compiled[] = self::externalScriptLoader($src, $attributes);
                }

                // 浏览器会忽略带 src 的 script 标签中的内联内容，保持相同行为。
                continue;
            }

            if ($body !== '') {
                $compiled[] = $body;
            }
        }

        return $compiled === [] ? '' : implode("\n;\n", $compiled)."\n";
    }

    /** @return list<string> */
    public static function allowedOrigins(mixed $widget): array
    {
        if (! self::isEnabled($widget)) {
            return [];
        }

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

    private static function isSafeScriptUrl(string $url): bool
    {
        if (str_starts_with($url, '/') && ! str_starts_with($url, '//')) {
            return true;
        }

        return strtolower((string) parse_url($url, PHP_URL_SCHEME)) === 'https'
            && (string) parse_url($url, PHP_URL_HOST) !== '';
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
