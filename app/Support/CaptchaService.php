<?php

namespace App\Support;

use Mews\Captcha\Facades\Captcha;

/**
 * 验证码服务:生成和校验图形验证码。
 * 基于 mews/captcha 包,按场景(register/login/trade)区分 session。
 */
class CaptchaService
{
    /**
     * 生成可用于 API 无状态校验的验证码。
     *
     * key 与答案按 mews/captcha 约定存入缓存,提交时无需依赖浏览器 Session。
     */
    public static function create(string $scene = 'default'): array
    {
        $captcha = Captcha::create($scene, true);

        return [
            'key' => (string) ($captcha['key'] ?? ''),
            'src' => (string) ($captcha['img'] ?? ''),
        ];
    }

    /**
     * 生成验证码图片(返回 base64 或直接输出)。
     * mews/captcha 的 captcha_src 生成 URL,captcha_img 生成 img 标签。
     */
    public static function src(string $scene = 'default'): string
    {
        return captcha_src($scene);
    }

    /**
     * 校验验证码。
     * mews/captcha 的校验通过 Request 自动完成,这里做手动校验。
     */
    public static function verify(string $scene, ?string $code, ?string $key = null): bool
    {
        if (! $code) {
            return false;
        }

        // 新版前台使用一次性 key 做无状态校验,避免首屏并发请求覆盖 Session Cookie。
        if ($key) {
            return captcha_api_check($code, $key, $scene);
        }

        // 兼容尚未升级的客户端:旧版仍按 Session 校验。
        return captcha_check($code);
    }

    /**
     * 根据配置检查某场景是否需要验证码。
     * 兼容两种 key 命名:captcha_{scene}(register/login) 和 trade_captcha(下单)。
     */
    public static function isEnabled(string $scene): bool
    {
        return (bool) (StorefrontConfig::get("captcha_{$scene}") ?? StorefrontConfig::get("{$scene}_captcha"));
    }
}
