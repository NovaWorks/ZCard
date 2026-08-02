<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * 未安装拦截中间件 EnsureInstalled 的测试。
 *
 * 通过控制 storage/app/installed 锁文件的存删,模拟"未安装"与"已安装"两种状态,
 * 验证未安装时访问任意页都会跳转 /install、安装向导 API 仍可用。
 */
class EnsureInstalledMiddlewareTest extends TestCase
{
    /** 锁文件路径 */
    private string $lockFile;

    protected function setUp(): void
    {
        parent::setUp();
        $this->lockFile = storage_path('app/installed');
    }

    protected function tearDown(): void
    {
        // 恢复到"已安装"状态,避免污染其他测试
        if (! file_exists($this->lockFile)) {
            file_put_contents($this->lockFile, json_encode([
                'version' => 'test',
                'installed_at' => now()->toIso8601String(),
            ]));
        }
        parent::tearDown();
    }

    public function test_uninstalled_browser_request_redirects_to_install(): void
    {
        $this->markAsUninstalled();

        $this->get('/')
            ->assertRedirect('/install');
    }

    public function test_uninstalled_arbitrary_browser_path_redirects_to_install(): void
    {
        $this->markAsUninstalled();

        $this->get('/products/some-product')
            ->assertRedirect('/install');
    }

    public function test_uninstalled_install_page_is_accessible(): void
    {
        $this->markAsUninstalled();

        // /install 走 catch-all 返回 SPA HTML(或未编译提示),不应被拦截
        $this->get('/install')->assertSuccessful();
    }

    public function test_uninstalled_install_status_api_returns_not_installed(): void
    {
        $this->markAsUninstalled();

        $this->getJson('/api/install/status')
            ->assertOk()
            ->assertJson(['installed' => false]);
    }

    public function test_uninstalled_other_api_returns_503_with_install_url(): void
    {
        $this->markAsUninstalled();

        $this->getJson('/api/products')
            ->assertStatus(503)
            ->assertJson(['install_required' => true])
            ->assertJsonPath('install_url', '/install');
    }

    public function test_uninstalled_static_assets_are_allowed(): void
    {
        $this->markAsUninstalled();

        // 静态资源应放行:不跳 install(302)、不返回 503
        // (测试环境无真实 favicon 文件可能 404,但重点是没被中间件拦截)
        $resp = $this->get('/favicon.ico');
        $this->assertNotEquals(302, $resp->getStatusCode());
        $this->assertNotEquals(503, $resp->getStatusCode());
    }

    public function test_uninstalled_with_empty_app_key_still_redirects(): void
    {
        // 仓库 .env 的 APP_KEY 默认留空(开箱即用),未安装时中间件需兜底生成 key,
        // 否则 EncryptCookies 中间件初始化会抛异常导致 500、安装向导无法加载
        $this->markAsUninstalled();
        config(['app.key' => '']);

        $this->get('/')
            ->assertRedirect('/install');

        // 中间件应已生成并注入 key
        $this->assertNotEmpty(config('app.key'));
    }

    public function test_installed_state_does_not_redirect(): void
    {
        $this->markAsInstalled();

        // 已安装:访问首页不应被中间件跳转到 /install
        // (可能因 DB 未就绪而 500,但不应该是 302 → /install)
        $resp = $this->get('/');
        $this->assertNotEquals(302, $resp->getStatusCode(), '已安装时不应 302 跳转');
        $this->assertStringNotContainsString('/install', $resp->headers->get('Location', ''));
    }

    public function test_installed_state_does_not_redirect_api(): void
    {
        $this->markAsInstalled();

        // 已安装:API 不应返回 503 install_required
        // (可能因 DB 未就绪而 500,但不应该是 503 安装提示)
        $resp = $this->getJson('/api/products');
        $this->assertNotEquals(503, $resp->getStatusCode(), '已安装时 API 不应返回 503 安装提示');
    }

    /**
     * 标记为未安装(删除锁文件)。
     */
    private function markAsUninstalled(): void
    {
        if (file_exists($this->lockFile)) {
            File::delete($this->lockFile);
        }
    }

    /**
     * 标记为已安装(写入锁文件)。
     */
    private function markAsInstalled(): void
    {
        file_put_contents($this->lockFile, json_encode([
            'version' => 'test',
            'installed_at' => now()->toIso8601String(),
        ]));
    }
}
