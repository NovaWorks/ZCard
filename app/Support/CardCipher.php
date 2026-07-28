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
    private static function encrypter(): Encrypter
    {
        $key = (string) config('zcard.card_encryption_key');
        if ($key === '') {
            throw new \RuntimeException('CARD_ENCRYPTION_KEY 未配置，请运行 zcard:install 或在 .env 设置。');
        }

        // 与 Laravel APP_KEY 同约定：base64: 前缀表示需先 base64 解码为原始字节。
        if (str_starts_with($key, 'base64:')) {
            $key = base64_decode(substr($key, 7)) ?: $key;
        }

        return new Encrypter($key, 'AES-256-CBC');
    }

    /** 加密单条明文卡密 → 密文 */
    public static function encrypt(string $plain): string
    {
        return self::encrypter()->encryptString($plain);
    }

    /** 解密单条密文 → 明文（发货/展示时用） */
    public static function decrypt(string $cipher): string
    {
        return self::encrypter()->decryptString($cipher);
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
