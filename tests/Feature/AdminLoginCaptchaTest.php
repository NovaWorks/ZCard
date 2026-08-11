<?php

namespace Tests\Feature;

use App\Models\Currency;
use App\Models\User;
use App\Support\StorefrontConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * 登录验证码:管理端(X-Client: sysadmin)跳过图形验证码,前台仍校验。
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
        // 开启登录验证码
        StorefrontConfig::setMany(['captcha_login' => true]);
        Cache::flush();
    }

    public function test_sysadmin_login_skips_captcha(): void
    {
        $this->seedBase();
        $user = User::factory()->create(['password' => bcrypt('password123')]);

        $resp = $this->withHeaders(['X-Client' => 'sysadmin'])
            ->postJson('/api/auth/login', [
                'email' => $user->email,
                'password' => 'password123',
                // 不带 captcha —— 管理端滑块场景
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

        // 前台无 X-Client 头 → 仍校验验证码 → 报验证码错误
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
}
