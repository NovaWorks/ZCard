<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\AnalyticsScript;
use App\Support\HtmlContentSanitizer;
use App\Support\ServiceWidgetScript;
use App\Support\StorefrontConfig;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

class StorefrontSettingsController extends Controller
{
    public function show(): JsonResponse
    {
        $config = StorefrontConfig::public();

        // 分站白标覆盖(spec §8.1):分站域名命中时,用分站自定义站点名/Logo/公告覆盖主站配置。
        $subsite = request()->attributes->get('subsite');
        if ($subsite && ($subsite->settings['is_subsite'] ?? false)) {
            $config['site_name'] = $subsite->settings['site_name'] ?? ($config['site_name'] ?? 'ZCard');
            $config['site_logo'] = $subsite->settings['logo'] ?? ($config['site_logo'] ?? '');
            // 安全(L-10):分站公告覆盖路径同样必须过 HTML 清洗,不能绕过主站清洗逻辑。
            $config['site_notice'] = HtmlContentSanitizer::sanitize(
                (string) ($subsite->settings['announcement'] ?? ($config['site_notice'] ?? '')),
            );
        }

        return response()->json($config);
    }

    /** 返回同源客服脚本，兼容 Chatwoot/Crisp 官方完整安装代码。 */
    public function serviceWidgetScript(): Response
    {
        $allowedHosts = StorefrontConfig::get('service_widget_allowed_hosts');
        $script = ServiceWidgetScript::compile(StorefrontConfig::get('service_widget'), $allowedHosts);

        return response($script, 200, [
            'Content-Type' => 'application/javascript; charset=UTF-8',
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
        ]);
    }

    /** 返回同源统计脚本(issue #39),兼容 GA4/百度统计官方完整安装代码。 */
    public function analyticsScript(): Response
    {
        $allowedHosts = StorefrontConfig::get('analytics_allowed_hosts');
        $script = AnalyticsScript::compile(StorefrontConfig::get('analytics'), $allowedHosts);

        return response($script, 200, [
            'Content-Type' => 'application/javascript; charset=UTF-8',
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
        ]);
    }
}
