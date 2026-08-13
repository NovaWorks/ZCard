<?php

namespace App\Supply;

/**
 * callback_url SSRF 校验(spec §8.5)
 * 禁止内网/loopback/link-local 地址(IPv4 + IPv6);仅允许 http/https。
 *
 * 2026-08 安全审计 M5 加固:
 * - 补齐 IPv6 私网/回环/链路本地/组播段(此前 IPv6 字面量全部放行);
 * - 解析域名时校验「全部」A/AAAA 记录均为公网地址(混入私网即拒绝);
 * - 提供 resolvePin() 供调用方用 CURLOPT_RESOLVE 钉死已校验的 IP,
 *   消除「校验时解析一次、请求时再解析一次」的 DNS rebinding TOCTOU。
 */
class CallbackUrlGuard
{
    /** 内网 IP 段(CIDR) */
    private const BLOCKED_RANGES = [
        '10.0.0.0/8',
        '172.16.0.0/12',
        '192.168.0.0/16',
        '127.0.0.0/8',
        '169.254.0.0/16', // link-local
        '0.0.0.0/8',
        '100.64.0.0/10',  // CGNAT
    ];

    /** 禁用的 IPv6 段 */
    private const BLOCKED_IPV6_RANGES = [
        '::/128',        // unspecified
        '::1/128',       // loopback
        '::ffff:0:0/96', // v4-mapped(按 v4 规则处理,但直接禁掉最稳)
        'fc00::/7',      // ULA
        'fe80::/10',     // link-local
        'ff00::/8',      // multicast
        '64:ff9b::/96',  // NAT64
    ];

    public function isAllowed(string $url): bool
    {
        $parsed = parse_url($url);
        if ($parsed === false) {
            return false;
        }
        if (! in_array(strtolower((string) ($parsed['scheme'] ?? '')), ['http', 'https'], true)) {
            return false;
        }

        $host = $parsed['host'] ?? '';
        if ($host === '' || strtolower($host) === 'localhost') {
            return false;
        }

        $ips = $this->resolveIps($host);
        if ($ips === []) {
            return false;
        }

        // 任一地址为私网/保留地址即拒绝(多宿主 DNS 也不能钻空子)。
        foreach ($ips as $ip) {
            if (! $this->isPublicIp($ip)) {
                return false;
            }
        }

        return true;
    }

    /**
     * 解析 host 的全部 A/AAAA 记录。域名解析失败且非 IP 字面量时返回空数组。
     *
     * @return list<string>
     */
    public function resolveIps(string $host): array
    {
        $host = trim($host, '[]');

        // IP 字面量
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return [$host];
        }

        $ips = [];
        $records = @dns_get_record($host, DNS_A | DNS_AAAA) ?: [];
        foreach ($records as $record) {
            $ip = $record['ip'] ?? $record['ipv6'] ?? null;
            if (is_string($ip)) {
                $ips[] = $ip;
            }
        }
        foreach (@gethostbynamel($host) ?: [] as $ip) {
            $ips[] = $ip;
        }

        return array_values(array_unique($ips));
    }

    /**
     * 供请求发送方使用的「钉死解析」信息:CURLOPT_RESOLVE 条目 + 请求目标端口。
     *
     * @return array{host: string, port: int, curl_entry: string}|null 无法解析到公网 IP 时返回 null
     */
    public function resolvePin(string $url): ?array
    {
        $parsed = parse_url($url);
        if ($parsed === false || ! in_array(strtolower((string) ($parsed['scheme'] ?? '')), ['http', 'https'], true)) {
            return null;
        }

        $host = $parsed['host'] ?? '';
        if ($host === '') {
            return null;
        }
        $scheme = strtolower((string) $parsed['scheme']);
        $port = (int) ($parsed['port'] ?? ($scheme === 'https' ? 443 : 80));

        $ips = $this->resolveIps($host);
        foreach ($ips as $ip) {
            if ($this->isPublicIp($ip)) {
                $curlIp = str_contains($ip, ':') ? '['.$ip.']' : $ip;

                return [
                    'host' => $host,
                    'port' => $port,
                    'curl_entry' => "{$host}:{$port}:{$curlIp}",
                ];
            }
        }

        return null;
    }

    public function isPublicIp(string $ip): bool
    {
        if (str_contains($ip, ':')) {
            foreach (self::BLOCKED_IPV6_RANGES as $range) {
                if ($this->ipInCidr($ip, $range)) {
                    return false;
                }
            }

            return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
        }

        foreach (self::BLOCKED_RANGES as $range) {
            if ($this->ipInCidr($ip, $range)) {
                return false;
            }
        }

        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
    }

    /** 通用 CIDR 判断(IPv4 + IPv6) */
    private function ipInCidr(string $ip, string $range): bool
    {
        [$subnet, $bits] = explode('/', $range);
        $bits = (int) $bits;

        if (str_contains($ip, ':')) {
            $ipBin = inet_pton($ip);
            $netBin = inet_pton($subnet);
            if ($ipBin === false || $netBin === false || strlen($ipBin) !== 16 || strlen($netBin) !== 16) {
                return false;
            }

            $fullBytes = intdiv($bits, 8);
            $remBits = $bits % 8;
            for ($i = 0; $i < $fullBytes; $i++) {
                if ($ipBin[$i] !== $netBin[$i]) {
                    return false;
                }
            }
            if ($remBits > 0) {
                $mask = 0xFF << (8 - $remBits);

                return (ord($ipBin[$fullBytes]) & $mask) === (ord($netBin[$fullBytes]) & $mask);
            }

            return true;
        }

        $ipLong = ip2long($ip);
        $subnetLong = ip2long($subnet);
        if ($ipLong === false || $subnetLong === false || $bits <= 0) {
            return false;
        }
        $mask = -1 << (32 - $bits);

        return ($ipLong & $mask) === ($subnetLong & $mask);
    }
}
