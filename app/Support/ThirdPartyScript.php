<?php

namespace App\Support;

use Illuminate\Support\Facades\Log;

/**
 * 将后台粘贴的第三方安装代码编译成可由同源端点加载的 JavaScript。
 *
 * 客服组件(Chatwoot/Crisp)与统计代码(GA4/百度统计)的官方安装代码通常包含完整的
 * <script> 标签，不能直接作为 script.textContent 执行；同时严格 CSP 也会阻止内联代码。
 * 本类只负责剥离标签、安全加载显式的 HTTPS 外部脚本，并提取 CSP 需要放行的来源。
 *
 * 安全约束(2026-08 安全审计 M2):
 * - 外部 script src 仅允许 https 且 host 必须在受信域名白名单内;
 * - 内联脚本体中引用的所有 https 来源同样必须在白名单内,否则整段丢弃;
 * - 白名单可配置(见子类 DEFAULT_ALLOWED_HOSTS 与对应的 *_allowed_hosts 配置项)。
 *   目的是防止超管账号被接管后借第三方脚本在商城同源加载任意代码(与 HttpOnly Cookie
 *   会话叠加后,同源脚本仍可借 CSRF Cookie 操作账户,因此必须收紧远程代码加载面)。
 *
 * 子类只需声明默认受信域名与日志标签，编译与来源提取逻辑完全共用。
 */
abstract class ThirdPartyScript
{
    /** 默认受信域名(允许被配置覆盖/追加),由子类声明。 */
    public const DEFAULT_ALLOWED_HOSTS = [];

    /** 日志与告警中标识脚本用途(如「客服脚本」「统计代码」)。 */
    abstract protected static function label(): string;

    public static function compile(mixed $config, mixed $allowedHosts = null): string
    {
        if (! static::isEnabled($config)) {
            return '';
        }

        $source = trim((string) ($config['script'] ?? ''));
        if ($source === '') {
            return '';
        }

        $hosts = static::normalizeHosts($allowedHosts);

        // 兼容历史上直接填写纯 JavaScript（不含 <script> 标签）的配置。
        if (! preg_match('/<script\b/i', $source)) {
            if (! static::inlineBodyAllowed($source, $hosts, 'raw-js')) {
                return '';
            }

            return $source."\n";
        }

        preg_match_all('/<script\b([^>]*)>(.*?)<\/script\s*>/is', $source, $matches, PREG_SET_ORDER);
        $compiled = [];

        foreach ($matches as $match) {
            $attributes = (string) ($match[1] ?? '');
            $body = trim((string) ($match[2] ?? ''));
            $src = static::attribute($attributes, 'src');

            if ($src !== null) {
                if (static::isSafeScriptUrl($src, $hosts)) {
                    $compiled[] = static::externalScriptLoader($src, $attributes);
                } else {
                    Log::warning(static::label().':外部脚本来源不在白名单内,已丢弃', ['src' => $src]);
                }

                // 浏览器会忽略带 src 的 script 标签中的内联内容，保持相同行为。
                continue;
            }

            if ($body !== '') {
                if (static::inlineBodyAllowed($body, $hosts, 'inline')) {
                    $compiled[] = $body;
                }
            }
        }

        return $compiled === [] ? '' : implode("\n;\n", $compiled)."\n";
    }

    /** @return list<string> */
    public static function allowedOrigins(mixed $config, mixed $allowedHosts = null): array
    {
        if (! static::isEnabled($config)) {
            return [];
        }

        $hosts = static::normalizeHosts($allowedHosts);
        $source = (string) ($config['script'] ?? '');
        if ($source === '') {
            return [];
        }

        // 官方代码可能通过变量动态拼接 SDK 地址，因此提取代码中的全部 HTTPS 来源。
        preg_match_all('~https://[^\s\"\'<>\\)]+~i', $source, $matches);
        $origins = [];

        // 白名单内的全部域名直接加入 CSP 放行:第三方 SDK 常从多个官方域名加载
        // 运行期资源(Crisp: client.crisp.chat → settings.crisp.chat;GA4:
        // googletagmanager.com 加载脚本、google-analytics.com 上报采集),
        // 这些域名不会出现在安装代码文本里。
        // 同时按"主域名 + 任意子域名"语义放行(填 example.com 或 *.example.com
        // 均表示该主域名及其任意层级子域名),CSP 追加 *.host 通配源。
        foreach ($hosts as $host) {
            $origins['https://'.$host] = true;
            $origins['https://*.'.$host] = true;
        }

        foreach ($matches[0] ?? [] as $url) {
            $parts = parse_url(rtrim((string) $url, '.,;'));
            $host = strtolower((string) ($parts['host'] ?? ''));
            if (strtolower((string) ($parts['scheme'] ?? '')) !== 'https' || $host === '') {
                continue;
            }
            // CSP 只放行白名单内的来源(与 compile() 的丢弃口径一致)。
            if (! static::hostAllowed($host, $hosts)) {
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
    protected static function inlineBodyAllowed(string $body, array $hosts, string $context): bool
    {
        // 内联代码允许引用任意 https 主机(白名单内的),禁止 http 与未知主机。
        preg_match_all('~https?://[^\s\"\'<>\\)]+~i', $body, $matches);

        foreach ($matches[0] ?? [] as $url) {
            $parts = parse_url(rtrim((string) $url, '.,;'));
            $scheme = strtolower((string) ($parts['scheme'] ?? ''));
            $host = strtolower((string) ($parts['host'] ?? ''));

            if ($scheme !== 'https') {
                Log::warning(static::label().":内联代码({$context})引用非 https 来源,已丢弃", ['url' => $url]);

                return false;
            }
            if (! static::hostAllowed($host, $hosts)) {
                Log::warning(static::label().":内联代码({$context})引用未授权主机,已丢弃", ['host' => $host]);

                return false;
            }
        }

        return true;
    }

    /**
     * 解析受信域名白名单(容错):
     * - 数组或字符串均接受;支持逗号/空格/分号/全角逗号分隔;
     * - 自动剥离 https:// 前缀、路径与尾点;
     * - 支持通配写法 `*.example.com`,归一化为 `example.com`(语义一致:主域名 +
     *   任意层级子域名均放行,如 a.example.com、b.c.example.com);
     * - 兼容"多域名被点号连成一段"的误填(如 app.chatwoot.com.cdn.chatwoot.com → 拆成两个);
     * - 非法条目(无点的单token等)直接丢弃;
     * - **最终白名单 = 默认官方域名 + 用户追加条目**。原因:第三方 SDK 常从多个
     *   官方域名加载资源(如 Crisp 主脚本在 client.crisp.chat、网站设置在
     *   settings.crisp.chat;GA4 脚本在 googletagmanager.com、采集在 google-analytics.com),
     *   只按填写内容放行会导致组件初始化失败。默认域名均为官方域名,合并不引入新风险面。
     *
     * @param  mixed  $allowedHosts  配置值(数组或逗号分隔字符串)
     */
    protected static function normalizeHosts(mixed $allowedHosts): array
    {
        $raw = is_array($allowedHosts)
            ? implode(',', array_map(fn (mixed $h) => trim((string) $h), $allowedHosts))
            : trim((string) $allowedHosts);

        $hosts = [];
        if ($raw !== '') {
            foreach (preg_split('/[\s,，;]+/u', $raw, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $entry) {
                $entry = strtolower(trim($entry));
                if ($entry === '') {
                    continue;
                }

                // 剥离协议与路径,只保留主机名
                if (str_contains($entry, '://')) {
                    $entry = (string) (parse_url($entry, PHP_URL_HOST) ?? '');
                } else {
                    $entry = (string) explode('/', $entry)[0];
                }
                $entry = rtrim($entry, '.');

                // 兼容"多个域名被点号连成一段"的误填(如 app.chatwoot.com.cdn.chatwoot.com)。
                // 注意:不能用变长 lookbehind 写法 (?<=\.(?:com|net|org|io|chat|app))\.
                // —— PCRE2 < 10.43(PHP 8.3 及以下)不支持变长 lookbehind,表达式无法编译,
                // 白名单非空时设置/客服接口直接 500。改用捕获组替换成逗号再拆分,语义一致。
                $splitEntry = preg_replace('/\.(com|net|org|io|chat|app)\./', '.$1,', $entry);
                $blocks = $splitEntry === null ? [$entry] : explode(',', $splitEntry);
                foreach ($blocks as $host) {
                    $host = trim($host);
                    // 通配写法 *.example.com 归一化为 example.com(主域名 + 全部子域名)。
                    if (str_starts_with($host, '*.')) {
                        $host = substr($host, 2);
                    }
                    if ($host !== ''
                        && preg_match('/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/', $host)) {
                        $hosts[] = $host;
                    }
                }
            }
        }

        // 默认官方域名始终放行(见方法注释),与用户追加条目合并去重。
        return array_values(array_unique(array_merge($hosts, static::DEFAULT_ALLOWED_HOSTS)));
    }

    /**
     * 判断 host 是否在白名单内:条目为主域名,命中 = 完全相等或为其任意层级子域名
     * (example.com 放行 a.example.com、b.c.example.com;notexample.com 不放行)。
     */
    protected static function hostAllowed(string $host, array $hosts): bool
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

    protected static function isEnabled(mixed $config): bool
    {
        return is_array($config)
            && filter_var($config['enabled'] ?? false, FILTER_VALIDATE_BOOL)
            && trim((string) ($config['script'] ?? '')) !== '';
    }

    protected static function attribute(string $attributes, string $name): ?string
    {
        $quotedName = preg_quote($name, '/');
        if (! preg_match('/\b'.$quotedName.'\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s>]+))/i', $attributes, $match)) {
            return null;
        }

        $value = $match[1] !== '' ? $match[1] : ($match[2] !== '' ? $match[2] : ($match[3] ?? ''));

        return html_entity_decode(trim($value), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    protected static function isSafeScriptUrl(string $url, array $hosts): bool
    {
        if (str_starts_with($url, '/') && ! str_starts_with($url, '//')) {
            return true;
        }

        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));

        return $scheme === 'https' && $host !== '' && static::hostAllowed($host, $hosts);
    }

    protected static function externalScriptLoader(string $src, string $attributes): string
    {
        $encodedSrc = json_encode($src, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES);
        $async = preg_match('/(?:^|\s)async(?:\s|=|$)/i', $attributes) === 1 ? 'true' : 'false';
        $defer = preg_match('/(?:^|\s)defer(?:\s|=|$)/i', $attributes) === 1 ? 's.defer=true;' : '';
        $type = static::attribute($attributes, 'type');
        $typeLine = $type !== null && in_array(strtolower($type), ['module', 'text/javascript', 'application/javascript'], true)
            ? 's.type='.json_encode($type, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT).';'
            : '';

        return '(function(){var s=document.createElement("script");'
            .'s.src='.$encodedSrc.';s.async='.$async.';'.$defer.$typeLine
            .'(document.head||document.documentElement).appendChild(s);}());';
    }
}
