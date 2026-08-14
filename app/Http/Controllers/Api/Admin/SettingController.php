<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Card;
use App\Support\ServiceWidgetScript;
use App\Support\StorefrontConfig;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * 店铺外观配置读写(spec §3.3)。代理 StorefrontConfig 静态门面。
 */
class SettingController extends Controller
{
    public function index(): JsonResponse
    {
        $all = StorefrontConfig::all();
        // 附带历史卡密数量,供前端在开启加密时提示风险
        $all['card_count'] = Card::count();

        return response()->json($all);
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'settings' => 'sometimes|array',
            'settings.*' => 'nullable',
        ]);

        // 兼容直接传 key-value 或包成 settings
        $kv = $data['settings'] ?? $request->except('settings');

        // 卡密加密开关；敏感字段统一由 StorefrontConfig 加密和脱敏。
        if (array_key_exists('card_encryption_enabled', $kv)) {
            $kv['card_encryption_enabled'] = (bool) $kv['card_encryption_enabled'];
        }
        if (array_key_exists('payment_callback_domain', $kv)) {
            $domain = rtrim(trim((string) ($kv['payment_callback_domain'] ?? '')), '/');
            $scheme = strtolower((string) parse_url($domain, PHP_URL_SCHEME));
            $path = parse_url($domain, PHP_URL_PATH);
            $hasExtraPath = $path !== null && ! in_array($path, ['', '/'], true);
            if ($domain !== '' && (filter_var($domain, FILTER_VALIDATE_URL) === false
                || ! in_array($scheme, ['http', 'https'], true)
                || $hasExtraPath
                || parse_url($domain, PHP_URL_QUERY) !== null
                || parse_url($domain, PHP_URL_FRAGMENT) !== null)) {
                throw ValidationException::withMessages([
                    'payment_callback_domain' => '支付回调域名必须是不带路径的 HTTP(S) 地址',
                ]);
            }
            $kv['payment_callback_domain'] = $domain;
        }
        StorefrontConfig::setMany($kv);

        // 回显时脱敏密钥
        $all = StorefrontConfig::all();
        if (! empty($all['card_encryption_key'])) {
            $all['card_encryption_key'] = StorefrontConfig::SECRET_MASK;
        }
        // 开启加密时,若已有历史卡密,附风险提示(前端展示)
        if (! empty($kv['card_encryption_enabled'])) {
            $cardCount = Card::count();
            if ($cardCount > 0) {
                $all['encryption_risk_cards'] = $cardCount;
            }
        }

        // 客服脚本可用性检测(修复「填了代码前台不显示」无反馈):脚本非空但编译结果为空,
        // 说明引用的外部域名不在受信白名单被整段丢弃,前台将回退链接浮窗模式。提示管理员补白名单。
        $widget = is_array($kv['service_widget'] ?? null) ? $kv['service_widget'] : null;
        if ($widget && trim((string) ($widget['script'] ?? '')) !== '') {
            $compiled = ServiceWidgetScript::compile($widget, StorefrontConfig::get('service_widget_allowed_hosts'));
            if (trim($compiled) === '') {
                $all['service_widget_script_dropped'] = true;
            }
        }

        return response()->json($all);
    }
}
