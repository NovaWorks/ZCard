<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\StorefrontConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * 管理员登录异常告警(issue #6):
 * - 首次登录只建立基线不告警;
 * - 陌生 IP/新设备登录 → 按已配置渠道发告警(TG/企业微信/邮件)。
 */
class AdminLoginAlertTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // 本套件验证告警逻辑;登录/注册验证码默认已开启(安全审计 L6),此处显式关闭。
        StorefrontConfig::setMany(['captcha_login' => false, 'captcha_register' => false]);
    }

    private function makeAdmin(): User
    {
        foreach (['super_admin', 'merchant', 'user'] as $r) {
            Role::firstOrCreate(['name' => $r]);
        }
        $user = User::factory()->create(['email' => 'boss@test.com', 'username' => 'boss']);
        $user->assignRole('super_admin');

        return $user;
    }

    private function loginPayload(string $email = 'boss@test.com'): array
    {
        return ['email' => $email, 'password' => 'password'];
    }

    public function test_first_login_creates_baseline_without_alert(): void
    {
        $admin = $this->makeAdmin();
        StorefrontConfig::setMany([
            'admin_alert_enabled' => true,
            'admin_alert_tg_token' => 'tok',
            'admin_alert_tg_chat_id' => '123',
        ]);

        $resp = $this->postJson('/api/auth/login', $this->loginPayload());
        $resp->assertOk();

        // 基线:登录审计已记录
        $this->assertDatabaseHas('security_audit_logs', [
            'actor_id' => $admin->id,
            'action' => 'login.success',
        ]);
        // 无告警请求发出
        Http::assertNothingSent();
    }

    public function test_unknown_ip_triggers_telegram_alert(): void
    {
        $admin = $this->makeAdmin();
        StorefrontConfig::setMany([
            'admin_alert_enabled' => true,
            'admin_alert_tg_token' => 'tok',
            'admin_alert_tg_chat_id' => '123',
        ]);

        Http::fake(['https://api.telegram.org/*' => Http::response(['ok' => true])]);

        // 第一次登录(基线):IP 1.1.1.1
        $this->withServerVariables(['REMOTE_ADDR' => '1.1.1.1'])
            ->postJson('/api/auth/login', $this->loginPayload())->assertOk();

        // 第二次登录:新 IP → 告警
        $resp = $this->withServerVariables(['REMOTE_ADDR' => '2.2.2.2'])
            ->postJson('/api/auth/login', $this->loginPayload());
        $resp->assertOk();

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'api.telegram.org')
                && str_contains($request['text'], '2.2.2.2')
                && str_contains($request['text'], '陌生 IP');
        });
    }

    public function test_known_ip_same_device_no_alert(): void
    {
        $admin = $this->makeAdmin();
        StorefrontConfig::setMany([
            'admin_alert_enabled' => true,
            'admin_alert_tg_token' => 'tok',
            'admin_alert_tg_chat_id' => '123',
        ]);

        Http::fake(['https://api.telegram.org/*' => Http::response(['ok' => true])]);

        // 两次同一 IP + 同一 UA → 第二次不告警
        $this->withServerVariables(['REMOTE_ADDR' => '1.1.1.1'])
            ->postJson('/api/auth/login', $this->loginPayload())->assertOk();
        $this->withServerVariables(['REMOTE_ADDR' => '1.1.1.1'])
            ->postJson('/api/auth/login', $this->loginPayload())->assertOk();

        Http::assertNothingSent();
    }

    public function test_alert_disabled_no_request(): void
    {
        $this->makeAdmin();
        StorefrontConfig::setMany([
            'admin_alert_enabled' => false,
            'admin_alert_tg_token' => 'tok',
            'admin_alert_tg_chat_id' => '123',
        ]);

        Http::fake(['https://api.telegram.org/*' => Http::response(['ok' => true])]);

        // 建基线
        $this->withServerVariables(['REMOTE_ADDR' => '1.1.1.1'])
            ->postJson('/api/auth/login', $this->loginPayload())->assertOk();
        // 陌生 IP 也不告警(总开关关闭)
        $this->withServerVariables(['REMOTE_ADDR' => '9.9.9.9'])
            ->postJson('/api/auth/login', $this->loginPayload())->assertOk();

        Http::assertNothingSent();
    }

    public function test_login_failure_is_audited(): void
    {
        $this->makeAdmin();
        $resp = $this->postJson('/api/auth/login', ['email' => 'boss@test.com', 'password' => 'wrong']);
        $resp->assertStatus(422);

        $this->assertDatabaseHas('security_audit_logs', ['action' => 'login.failed']);
    }
}
