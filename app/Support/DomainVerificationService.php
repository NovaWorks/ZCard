<?php

namespace App\Support;

use App\Models\SubsiteDomain;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * 分站域名验证服务(DNS TXT + HTTP well-known 双方案,任一通过即验证成功)。
 *
 * 方案 A (DNS TXT):分站主添加 TXT 记录 _zcard-verify.{domain} = {token}
 * 方案 B (HTTP):分站主在域名根目录放 .well-known/zcard-verify.txt 内容 = {token}
 *
 * 比 dujiao-next(仅手动审批)和 acg-faka(零验证)更安全。
 */
class DomainVerificationService
{
    /** well-known 验证路径 */
    private const WELL_KNOWN_PATH = '/.well-known/zcard-verify.txt';

    /** DNS TXT 前缀 */
    private const DNS_PREFIX = '_zcard-verify';

    /**
     * 验证域名归属(DNS TXT + HTTP 双查,任一通过即成功)。
     *
     * @return array{verified: bool, method: string|null, message: string}
     */
    public static function verify(SubsiteDomain $domain): array
    {
        if (! $domain->verification_token) {
            return ['verified' => false, 'method' => null, 'message' => '无验证 token'];
        }

        $token = $domain->verification_token;
        $host = $domain->domain;

        if (! self::isSafePublicDomain($host)) {
            $domain->update(['verification_status' => 'failed']);

            return ['verified' => false, 'method' => null, 'message' => '域名格式无效或解析到非公网地址'];
        }

        // 方案 A: DNS TXT 查询
        $dnsResult = self::checkDnsTxt($host, $token);
        if ($dnsResult) {
            self::markVerified($domain, 'dns_txt');

            return ['verified' => true, 'method' => 'dns_txt', 'message' => 'DNS TXT 验证通过'];
        }

        // 方案 B: HTTP well-known 查询
        $httpResult = self::checkHttpWellKnown($host, $token);
        if ($httpResult) {
            self::markVerified($domain, 'http_well_known');

            return ['verified' => true, 'method' => 'http_well_known', 'message' => 'HTTP 验证通过'];
        }

        // 两种都未通过
        $domain->update(['verification_status' => 'failed']);

        return ['verified' => false, 'method' => null, 'message' => 'DNS TXT 和 HTTP 验证均未通过,请检查配置'];
    }

    /**
     * DNS TXT 查询:查 _zcard-verify.{domain} 的 TXT 记录是否含 token。
     */
    private static function checkDnsTxt(string $domain, string $token): bool
    {
        $record = self::DNS_PREFIX.'.'.$domain;

        try {
            // dns_get_record 查 TXT
            $records = @dns_get_record($record, DNS_TXT);
            if ($records === false || empty($records)) {
                return false;
            }

            foreach ($records as $r) {
                $txt = $r['txt'] ?? '';
                // TXT 值可能被双引号包裹或拼接,trim 后精确匹配
                if (trim($txt, '" ') === $token) {
                    return true;
                }
            }
        } catch (\Throwable $e) {
            Log::debug("域名 DNS TXT 查询异常 {$record}: {$e->getMessage()}");
        }

        return false;
    }

    /**
     * HTTP well-known 查询:请求 https://{domain}/.well-known/zcard-verify.txt,核对内容。
     */
    private static function checkHttpWellKnown(string $domain, string $token): bool
    {
        $url = 'https://'.$domain.self::WELL_KNOWN_PATH;

        $ips = self::resolvePublicIps($domain);
        if ($ips === []) {
            return false;
        }

        // 固定本次请求使用已校验的公网 IP，防止 DNS 重绑定；禁止跳转到其他目标。
        $ip = $ips[0];
        $curlIp = str_contains($ip, ':') ? '['.$ip.']' : $ip;

        try {
            $response = Http::timeout(10)
                ->withoutRedirecting()
                ->withOptions([
                    'curl' => [CURLOPT_RESOLVE => ["{$domain}:443:{$curlIp}"]],
                ])
                ->get($url);

            if (! $response->successful()) {
                return false;
            }

            $body = trim($response->body());

            // 文件内容 = token(允许前后空白/换行)
            return $body === $token;
        } catch (\Throwable $e) {
            Log::debug("域名 HTTP well-known 查询异常 {$url}: {$e->getMessage()}");
        }

        return false;
    }

    /** 仅接受不含协议/端口的规范公网域名。 */
    public static function isSafePublicDomain(string $domain): bool
    {
        $domain = strtolower(rtrim(trim($domain), '.'));
        if ($domain === '' || strlen($domain) > 253 || filter_var($domain, FILTER_VALIDATE_IP)) {
            return false;
        }
        if (! preg_match('/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/', $domain)) {
            return false;
        }

        return self::resolvePublicIps($domain) !== [];
    }

    /** @return list<string> */
    private static function resolvePublicIps(string $domain): array
    {
        $ips = [];
        $records = @dns_get_record($domain, DNS_A | DNS_AAAA) ?: [];
        foreach ($records as $record) {
            $ip = $record['ip'] ?? $record['ipv6'] ?? null;
            if (is_string($ip)) {
                $ips[] = $ip;
            }
        }
        foreach (@gethostbynamel($domain) ?: [] as $ip) {
            $ips[] = $ip;
        }
        $ips = array_values(array_unique($ips));

        foreach ($ips as $ip) {
            if (! filter_var(
                $ip,
                FILTER_VALIDATE_IP,
                FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
            )) {
                return [];
            }
        }

        return $ips;
    }

    /**
     * 标记验证通过 + 自动激活。
     */
    private static function markVerified(SubsiteDomain $domain, string $method): void
    {
        $domain->update([
            'verification_status' => 'verified',
            'status' => 'active',
            'verified_at' => now(),
        ]);

        // 清缓存让 ResolveSubsite 立即生效
        Cache::forget("subsite:domain:{$domain->domain}");

        Log::info("域名 {$domain->domain} 验证通过(method={$method})");
    }

    /**
     * 获取验证指引(供前端展示给分站主)。
     *
     * @return array{dns_record: string, dns_value: string, http_url: string, http_content: string}
     */
    public static function getInstructions(SubsiteDomain $domain): array
    {
        $token = $domain->verification_token ?? '';
        $host = $domain->domain;

        return [
            'dns_record' => self::DNS_PREFIX.'.'.$host,
            'dns_value' => $token,
            'dns_type' => 'TXT',
            'http_url' => 'https://'.$host.self::WELL_KNOWN_PATH,
            'http_content' => $token,
        ];
    }
}
