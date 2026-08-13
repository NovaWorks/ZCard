<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\StorefrontConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * M3:浏览器会话改 HttpOnly Cookie 混合认证回归。
 *
 * 后端行为验证:
 * 1. 注册/登录会写入 web guard 会话(SPA 凭 HttpOnly Cookie 保持登录态的前提);
 * 2. auth:sanctum 在无 Bearer 时会回退读取 web 会话(Sanctum Guard 的会话回退);
 * 3. 登出会销毁会话中的登录态;
 * 4. 纯 Bearer 的 API 客户端不受影响。
 *
 * 注:phpunit 的 SESSION_DRIVER=array 不落 Cookie,故用 withSession 注入会话数据
 * 模拟"带着会话 Cookie 的请求",并 forgetGuards() 避免 AuthManager 跨请求缓存假阳性。
 */
class CookieSessionAuthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // 本套件验证会话本身,关闭默认开启的注册验证码(安全审计 L6)。
        StorefrontConfig::setMany(['captcha_register' => false, 'captcha_login' => false]);
        Role::firstOrCreate(['name' => 'user']);
        Role::firstOrCreate(['name' => 'super_admin']);
    }

    public function test_register_writes_web_session_and_sanctum_reads_it_without_bearer(): void
    {
        $this->postJson('/api/auth/register', [
            'username' => 'cookieuser',
            'email' => 'cookie@test.com',
            'password' => 'password123',
        ])->assertStatus(201);

        // 注册后 web guard 会话已建立(前端刷新后凭 Cookie 恢复登录态的基础)
        $this->assertTrue(Auth::guard('web')->check());

        // 模拟新请求:清 guard 缓存,仅注入会话数据(等价于带会话 Cookie 的请求),
        // 不带 Authorization 头 → auth:sanctum 必须回退到 web 会话。
        app('auth')->forgetGuards();
        $user = User::where('username', 'cookieuser')->firstOrFail();
        $this->withSession([
            Auth::guard('web')->getName() => $user->id,
            'password_hash_web' => $user->getAuthPassword(),
        ]);

        $this->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('username', 'cookieuser');
    }

    public function test_login_writes_web_session(): void
    {
        $this->postJson('/api/auth/register', [
            'username' => 'loginuser',
            'email' => 'login@test.com',
            'password' => 'password123',
        ])->assertStatus(201);

        app('auth')->forgetGuards();
        $this->postJson('/api/auth/login', [
            'email' => 'login@test.com',
            'password' => 'password123',
        ])->assertStatus(200);

        $this->assertTrue(Auth::guard('web')->check());
    }

    public function test_logout_destroys_web_session(): void
    {
        $this->postJson('/api/auth/register', [
            'username' => 'logoutuser',
            'email' => 'logout@test.com',
            'password' => 'password123',
        ])->assertStatus(201);

        $this->postJson('/api/auth/logout')->assertOk();

        // 会话被 invalidate,登录态键必须消失
        $this->assertNull(session()->get(Auth::guard('web')->getName()));
        app('auth')->forgetGuards();
        $this->assertFalse(Auth::guard('web')->check());
    }

    public function test_bearer_token_still_works_for_api_clients(): void
    {
        $resp = $this->postJson('/api/auth/register', [
            'username' => 'apiuser',
            'email' => 'api@test.com',
            'password' => 'password123',
        ])->assertStatus(201);

        $token = $resp->json('token');
        app('auth')->forgetGuards();

        // 纯 Bearer 客户端(不依赖会话)
        $this->withToken($token)->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('username', 'apiuser');
    }
}
