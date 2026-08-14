<?php

namespace App\Support;

use App\Models\Setting;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;

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
        return (bool) StorefrontConfig::get('card_encryption_enabled');
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
        $cfgKey = Setting::where('key', 'card_encryption_key')->value('value');
        if ($cfgKey) {
            try {
                return (string) Crypt::decryptString((string) $cfgKey);
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
     * 安全(M-9):加密开关默认开启(密钥存在时)。为兼容存量明文卡,先做「密文形态
     * 识别」——只有形如 Laravel 加密载荷(base64 JSON 含 iv/value/mac)的内容才尝试解密;
     * 历史明文卡密(不含该结构)直接原样返回,发货不受影响。
     *
     * $strict=true(发货链路):形态像密文但解密失败(密钥变更/损坏)时抛异常阻断发货
     * 并留 error 审计,而非把密文当卡密发给买家;$strict=false(展示/预览)降级返回原值。
     */
    public static function decrypt(string $cipher, bool $strict = false): string
    {
        // 密文形态识别:非 Laravel 加密载荷 = 历史明文卡,直接返回(两种模式一致)
        if (! self::looksEncrypted($cipher)) {
            return $cipher;
        }

        try {
            return self::encrypter()->decryptString($cipher);
        } catch (\Throwable $e) {
            if ($strict) {
                Log::error('卡密解密失败(发货链路,已阻断):疑似密钥变更或数据损坏', [
                    'error' => $e->getMessage(),
                    'cipher_head' => mb_substr($cipher, 0, 24),
                ]);
                throw new \RuntimeException('卡密解密失败,已阻断发货:疑似加密密钥变更或数据损坏,请立即检查密钥配置');
            }

            // 展示链路:降级返回原值,避免 500
            Log::debug('卡密解密失败,按原值返回(可能为密钥变更)', [
                'error' => $e->getMessage(),
            ]);

            return $cipher;
        }
    }

    /** 是否形如 Laravel 加密载荷(base64 JSON 含 iv/value/mac 三键) */
    private static function looksEncrypted(string $cipher): bool
    {
        $decoded = base64_decode($cipher, true);
        if ($decoded === false || $decoded === '' || $cipher === '') {
            return false;
        }
        $json = json_decode($decoded, true);

        return is_array($json)
            && isset($json['iv'], $json['value'], $json['mac']);
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
