<?php

namespace App\Support;

use Illuminate\Encryption\Encrypter;

/**
 * 卡密加解密工具（spec §6.1 决策3）。
 *
 * 设计要点：
 * - 加密用独立密钥 CARD_ENCRYPTION_KEY（与 APP_KEY 解耦），AES-256-CBC。
 * - 去重靠 sha256 明文 hash 存 content_hash 列（可索引），不靠密文。
 * - 不用 Laravel `encrypted` cast（其随机 IV 使密文不可比对）。
 *
 * Phase 0 提供基础加解密；批量加密（导入）在 Phase 1 实现时复用 encrypt()。
 */
class CardCipher
{
    /**
     * 是否开启卡密加密(后台配置,默认关闭=明文存储,正常导入)。
     */
    public static function isEnabled(): bool
    {
        return (bool) \App\Support\StorefrontConfig::get('card_encryption_enabled');
    }

    private static function encrypter(): Encrypter
    {
        $key = self::resolveKey();
        if ($key === '') {
            throw new \RuntimeException('卡密加密已开启但密钥未配置，请到后台「店铺设置 → 安全设置」配置密钥。');
        }

        // 与 Laravel APP_KEY 同约定：base64: 前缀表示需先 base64 解码为原始字节。
        if (str_starts_with($key, 'base64:')) {
            $key = base64_decode(substr($key, 7)) ?: $key;
        }

        return new Encrypter($key, 'AES-256-CBC');
    }

    /**
     * 解析卡密加密密钥。
     * 优先级:后台配置(settings 表存 Crypt 密文) > .env 的 CARD_ENCRYPTION_KEY。
     * 直接查表取真实值(不经 StorefrontConfig::get,避免脱敏值)。
     */
    private static function resolveKey(): string
    {
        $cfgKey = \App\Models\Setting::where('key', 'card_encryption_key')->value('value');
        if ($cfgKey) {
            try {
                return (string) \Illuminate\Support\Facades\Crypt::decryptString((string) $cfgKey);
            } catch (\Throwable) {
                // 兼容历史明文存储
                return (string) $cfgKey;
            }
        }

        return (string) config('zcard.card_encryption_key');
    }

    /**
     * 加密单条明文卡密 → 密文。
     * 未开启加密时原样返回(明文存储,正常导入)。
     */
    public static function encrypt(string $plain): string
    {
        if (! self::isEnabled()) {
            return $plain;
        }

        return self::encrypter()->encryptString($plain);
    }

    /**
     * 解密单条密文 → 明文(发货/展示时用)。
     * 兼容两种状态:
     * - 未开启加密:新卡密为明文原样返回;历史加密卡密尝试用已配置密钥解密(失败则视为明文)
     * - 已开启加密:AES 解密;解密失败(历史明文/密钥变更)降级返回原值,避免展示/发货报错
     */
    public static function decrypt(string $cipher): string
    {
        try {
            if (self::isEnabled()) {
                return self::encrypter()->decryptString($cipher);
            }

            // 未开启:若是历史密文则尝试解密(有 key 才可能成功),否则按明文返回
            return self::encrypter()->decryptString($cipher);
        } catch (\Throwable) {
            return $cipher;
        }
    }

    /** 明文 → sha256 hash（用于去重索引 content_hash） */
    public static function hash(string $plain): string
    {
        return hash('sha256', $plain);
    }

    /** 加密并算 hash，返回 [content, content_hash]，供插入用 */
    public static function encryptWithHash(string $plain): array
    {
        return [
            'content' => self::encrypt($plain),
            'content_hash' => self::hash($plain),
        ];
    }
}
