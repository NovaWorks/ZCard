<?php

namespace Tests\Feature;

use App\Models\Currency;
use App\Models\User;
use App\Support\StorefrontConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Mews\Captcha\Facades\Captcha;
use Tests\TestCase;

/**
 * 登录验证码:后台开启后,前台与后台(带 X-Client: sysadmin 头)都必须输入验证码。
 */
class AdminLoginCaptchaTest extends TestCase
{
    use RefreshDatabase;

    private function seedBase(): void
    {
        Currency::firstOrCreate(['code' => 'CNY'], [
            'name' => '人民币', 'symbol' => '¥', 'symbol_position' => 'before',
            'decimal_places' => 2, 'exchange_rate' => '1', 'is_base' => true,
            'is_enabled' => true, 'sort' => 0,
        ]);
        StorefrontConfig::setMany(['captcha_login' => true]);
        Cache::flush();
    }

    public function test_sysadmin_login_requires_captcha_when_enabled(): void
    {
        $this->seedBase();
        $user = User::factory()->create(['password' => bcrypt('password123')]);

        // 管理端不带 captcha → 同样报验证码错误
        $resp = $this->withHeaders(['X-Client' => 'sysadmin'])
            ->postJson('/api/auth/login', [
                'email' => $user->email,
                'password' => 'password123',
            ]);

        $resp->assertStatus(422);
        $resp->assertJsonValidationErrors('captcha');
    }

    public function test_sysadmin_login_succeeds_with_valid_captcha(): void
    {
        $this->seedBase();
        $user = User::factory()->create(['password' => bcrypt('password123')]);

        // 预置验证码到 session(模拟用户输入正确验证码)
        $this->withSession(['captcha.login' => ['key' => 'test-key', 'phrase' => '123456']]);
        Captcha::shouldReceive('check')->andReturn(true);

        $resp = $this->withHeaders(['X-Client' => 'sysadmin'])
            ->postJson('/api/auth/login', [
                'email' => $user->email,
                'password' => 'password123',
                'captcha' => '123456',
            ]);

        $resp->assertOk();
        $this->assertNotEmpty($resp->json('token'));
    }

    public function test_storefront_login_still_requires_captcha(): void
    {
        $this->seedBase();
        $user = User::factory()->create(['password' => bcrypt('password123')]);

        $resp = $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'password123',
        ]);

        $resp->assertStatus(422);
        $resp->assertJsonValidationErrors('captcha');
    }

    public function test_login_captcha_disabled_works_for_everyone(): void
    {
        $this->seedBase();
        StorefrontConfig::setMany(['captcha_login' => false]);
        $user = User::factory()->create(['password' => bcrypt('password123')]);

        $resp = $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'password123',
        ]);

        $resp->assertOk();
    }

    /** 无状态 key 模式：/api/captcha/{scene} 返回 enabled + 图片 + key，登录凭 key+code 校验 */
    public function test_stateless_captcha_endpoint_and_login_flow(): void
    {
        $this->seedBase();
        $user = User::factory()->create(['password' => bcrypt('password123')]);

        $captchaResp = $this->getJson('/api/captcha/login');
        $captchaResp->assertOk();
        $captchaResp->json('enabled') === true || $this->fail('captcha endpoint must expose enabled flag');
        $this->assertNotEmpty($captchaResp->json('key'));
        $this->assertStringStartsWith('data:image', (string) $captchaResp->json('src'));

        // 错误验证码：真 check_api 校验失败 → 422
        $wrong = $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'password123',
            'captcha' => 'wrong-code',
            'captcha_key' => $captchaResp->json('key'),
        ]);
        $wrong->assertStatus(422);
        $wrong->assertJsonValidationErrors('captcha');

        // 正确验证码：mock check_api 通过(mews/captcha 方法名为 snake_case)
        Captcha::shouldReceive('check_api')->andReturn(true);
        $ok = $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'password123',
            'captcha' => '123456',
            'captcha_key' => $captchaResp->json('key'),
        ]);
        $ok->assertOk();
        $this->assertNotEmpty($ok->json('token'));
    }
}
