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
    public static function verify(string $scene, ?string $code): bool
    {
        if (! $code) {
            return false;
        }

        // mews/captcha 把验证码存入 session,key 格式 captcha.{scene}
        return captcha_check($code, $scene);
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
