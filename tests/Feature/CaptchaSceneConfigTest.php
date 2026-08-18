<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * 验证码「看不全 → 一直报错」回归。
 *
 * mews/captcha 的 configure() 在 config/captcha.php 缺少对应场景段时**什么都不做**，
 * 直接沿用类默认值(length=5、width=120)。5 个字符按
 * marginLeft = padding + key * (width - padding) / length 分布,最后一位会被画出画布右边界,
 * 用户照着看不全的图输入,必然反复提示「验证码错误」。
 *
 * trade 段当初已因此修过一次,register/login 被漏掉 —— 本测试锁住全部前台在用的场景。
 */
class CaptchaSceneConfigTest extends TestCase
{
    use RefreshDatabase;

    /** 前台/后台实际请求的场景:注册页与找回密码页用 register,登录页(含 sysadmin)用 login,收银台用 trade */
    private const FRONTEND_SCENES = ['register', 'login', 'trade'];

    /** trade 实测 (120-4)/4 = 29px/字符 时完整可读;低于该阈值即有裁切风险 */
    private const MIN_WIDTH_PER_CHAR = 28;

    public function test_every_frontend_captcha_scene_has_explicit_config(): void
    {
        foreach (self::FRONTEND_SCENES as $scene) {
            $this->assertIsArray(
                config("captcha.{$scene}"),
                "captcha.{$scene} 配置段缺失 → mews 会回落到类默认 length=5,第 5 位被裁出画布",
            );
        }
    }

    public function test_characters_fit_inside_canvas_for_every_scene(): void
    {
        foreach (self::FRONTEND_SCENES as $scene) {
            $config = config("captcha.{$scene}");
            $padding = $config['textLeftPadding'] ?? 4;
            $widthPerChar = ($config['width'] - $padding) / $config['length'];

            $this->assertGreaterThanOrEqual(
                self::MIN_WIDTH_PER_CHAR,
                $widthPerChar,
                "场景 {$scene}: 每字符仅 {$widthPerChar}px,字符会被画出画布边界导致看不全",
            );
        }
    }

    public function test_generated_captcha_has_the_configured_number_of_characters(): void
    {
        Cache::flush();

        foreach (self::FRONTEND_SCENES as $scene) {
            $response = $this->getJson("/api/captcha/{$scene}")->assertOk();
            $key = (string) $response->json('key');
            $this->assertNotSame('', $key);

            // mews 把答案按 get_cache_key() = 'captcha_'.md5($key) 存入缓存
            $answer = Cache::get('captcha_'.md5($key));
            $this->assertNotNull($answer, "场景 {$scene}: 未取到验证码答案");

            $length = is_array($answer) ? count($answer) : mb_strlen((string) $answer);
            $this->assertSame(
                config("captcha.{$scene}.length"),
                $length,
                "场景 {$scene}: 实际生成 {$length} 位,与配置不符(配置未生效说明又回落到了类默认值)",
            );
        }
    }

    public function test_generated_image_matches_configured_canvas(): void
    {
        foreach (self::FRONTEND_SCENES as $scene) {
            $src = (string) $this->getJson("/api/captcha/{$scene}")->assertOk()->json('src');
            $binary = base64_decode(substr($src, strpos($src, ',') + 1));
            $size = getimagesizefromstring($binary);

            $this->assertSame(config("captcha.{$scene}.width"), $size[0]);
            $this->assertSame(config("captcha.{$scene}.height"), $size[1]);
        }
    }
}
