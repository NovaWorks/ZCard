<?php

namespace App\Supply;

/**
 * callback_url SSRF 校验(spec §8.5)
 * 禁止内网/loopback/link-local 地址;仅允许 http/https。
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
    ];

    public function isAllowed(string $url): bool
    {
        $parsed = parse_url($url);
        if ($parsed === false) return false;
        if (! in_array($parsed['scheme'] ?? '', ['http', 'https'])) return false;

        $host = $parsed['host'] ?? '';
        if ($host === '' || $host === 'localhost') return false;

        // 解析主机为 IP(若是域名)
        $ip = gethostbyname($host);
        if ($ip === $host && ! filter_var($host, FILTER_VALIDATE_IP)) {
            // 域名解析失败且非 IP 字面量 → 拒绝
            return false;
        }
        $ip = filter_var($ip, FILTER_VALIDATE_IP) ? $ip : $host;

        foreach (self::BLOCKED_RANGES as $range) {
            if ($this->ipInRange($ip, $range)) return false;
        }
        return true;
    }

    private function ipInRange(string $ip, string $range): bool
    {
        [$subnet, $maskBits] = explode('/', $range);
        $ipLong = ip2long($ip);
        $subnetLong = ip2long($subnet);
        if ($ipLong === false || $subnetLong === false) return false;
        $mask = -1 << (32 - (int) $maskBits);
        return ($ipLong & $mask) === ($subnetLong & $mask);
    }
}
