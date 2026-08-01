<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\StorefrontConfig;
use Illuminate\Http\JsonResponse;

class StorefrontSettingsController extends Controller
{
    public function show(): JsonResponse
    {
        $config = StorefrontConfig::all();

        // 分站白标覆盖(spec §8.1):分站域名命中时,用分站自定义站点名/Logo/公告覆盖主站配置。
        $subsite = request()->attributes->get('subsite');
        if ($subsite && ($subsite->settings['is_subsite'] ?? false)) {
            $config['site_name'] = $subsite->settings['site_name'] ?? ($config['site_name'] ?? 'ZCard');
            $config['site_logo'] = $subsite->settings['logo'] ?? ($config['site_logo'] ?? '');
            $config['site_notice'] = $subsite->settings['announcement'] ?? ($config['site_notice'] ?? '');
        }

        return response()->json($config);
    }
}
