<?php

namespace App\Supply;

/**
 * 货源对接 HMAC-SHA256 签名工具(spec §4.2)
 * 供货API鉴权 + 回调签名共用。
 *
 * 签名串 = METHOD\nPATH(不含query)\ntimestamp\nnonce\nmd5(body)
 * 签名 = hex_lower(HMAC_SHA256(api_secret, 签名串))
 */
class HmacSigner
{
    /**
     * 构建签名串。PATH 必须不含 query string(调用方传参前剥离)。
     */
    public static function buildSignString(string $method, string $path, string $timestamp, string $nonce, string $bodyMd5): string
    {
        return implode("\n", [$method, $path, $timestamp, $nonce, $bodyMd5]);
    }

    /**
     * 构建含 query 完整性保护的签名串(低危修复:此前 query 不参与签名,
     * 中间人可在窗口内改写查询参数转发)。第 6 段 = md5(原始 query string,空串=空 md5)。
     * 服务端双口径验签(先旧后新),客户端从新口径起逐步升级。
     */
    public static function buildSignStringWithQuery(string $method, string $path, string $rawQuery, string $timestamp, string $nonce, string $bodyMd5): string
    {
        return implode("\n", [$method, $path, $timestamp, $nonce, $bodyMd5, md5($rawQuery)]);
    }

    /**
     * 计算签名(hex 小写)。
     */
    public static function sign(string $secret, string $signString): string
    {
        return hash_hmac('sha256', $signString, $secret);
    }

    /**
     * 常数时间比较验签。
     */
    public static function verify(string $secret, string $signString, string $signature): bool
    {
        $expected = self::sign($secret, $signString);

        return hash_equals($expected, $signature);
    }

    /**
     * 检查 timestamp 是否在 ±skew 窗口内(spec §8.5 timestamp_skew)。
     */
    public static function timestampValid(int $timestamp, int $skew): bool
    {
        return abs(time() - $timestamp) <= $skew;
    }
}
