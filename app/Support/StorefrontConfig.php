<?php

namespace App\Support;

use App\Models\Setting;

/**
 * 店铺外观配置辅助(spec §3.3, group=storefront)。
 * 读写 settings 表;提供默认值。
 */
class StorefrontConfig
{
    /** 所有配置 key 及默认值 */
    public static function defaults(): array
    {
        return [
            'category_nav_style' => 'pills',
            'list_default_view' => 'grid',
            'grid_columns' => 4,
            'page_size' => 12,
            'default_order' => 'newest',
            'show_stock' => true,
            'show_sales' => true,
            'show_price' => true,
            'show_description' => true,
            'show_reviews' => false,
            'allow_post_review' => true,
            'review_need_audit' => true,
            'show_featured' => true,
            'featured_count' => 8,
            'show_hot_tags' => true,
            'hot_tag_categories' => [],
            'order_query_password' => true,
            'trade_captcha' => true,
            'order_close_minutes' => 15,
            'contact_type' => 'email',
            'guest_checkout' => true,
            'require_contact' => true,

            // 安全与注册设置
            'register_open' => true,
            'register_type' => 'email',
            'captcha_register' => false,
            'captcha_login' => false,
            'username_min_length' => 3,
            'forget_type' => 'email',

            // 系统运维
            'maintenance_mode' => false,
            'maintenance_message' => '系统维护中,请稍后再来访问。',
            'site_notice' => '',

            // 站点与页脚信息(全前台通用,后台「店铺设置-站点信息」编辑)
            'site_name' => 'ZCard',
            'site_url' => '',
            'site_logo' => '',
            'site_description' => 'ZCard — 现代化插件制虚拟商品自动发卡平台,7×24 小时极速发货,安全可靠。',
            'footer_about' => 'ZCard 是现代化的插件制虚拟商品自动发卡平台,7×24 小时极速发货、安全可靠,为您提供优质的数字商品购物体验。',
            'footer_links' => [
                ['title' => '首页', 'url' => '/'],
                ['title' => '订单查询', 'url' => '/orders/query'],
                ['title' => '用户登录', 'url' => '/login'],
                ['title' => '用户注册', 'url' => '/register'],
            ],
            'footer_contact' => [
                ['label' => '客服', 'value' => ''],
                ['label' => '客服邮箱', 'value' => 'support@example.com'],
                ['label' => '工作时间', 'value' => '7×24 小时'],
                ['label' => 'Telegram', 'value' => ''],
            ],
            'footer_social' => [
                ['name' => 'Telegram 群', 'icon' => '✈️', 'url' => ''],
                ['name' => 'QQ 群', 'icon' => '💬', 'url' => ''],
                ['name' => '微信', 'icon' => '🟢', 'url' => ''],
                ['name' => 'Discord', 'icon' => '🎮', 'url' => ''],
            ],
            'footer_copyright' => '© 2026 ZCard · 现代化插件制自动发卡系统',
            // 第三方统计代码(百度统计/Google Analytics 等,原样注入到页面底部)
            'footer_analytics' => '',

            // 邮件设置(SMTP)
            'mail_enabled' => false,
            'mail_host' => '',
            'mail_port' => 465,
            'mail_encryption' => 'ssl',
            'mail_username' => '',
            'mail_password' => '',
            'mail_from_address' => '',
            'mail_from_name' => 'ZCard',

            // 短信设置(阿里云/腾讯云,从店铺设置配置)
            'sms_enabled' => false,
            'sms_platform' => 'aliyun',
            'sms_access_key' => '',
            'sms_access_secret' => '',
            'sms_sign_name' => '',
            'sms_template_code' => '',           // 验证码模板 CODE
            'sms_delivery_template_code' => '',   // 发货通知模板 CODE


            // 提现设置(预留)
            'cash_min' => 100,
            'cash_fee' => 5,
            'cash_type_alipay' => true,
            'cash_type_wechat' => true,
            'cash_type_usdt' => true,

            // 多货币与多语言
            'base_currency' => 'CNY',
            'default_display_currency' => 'CNY',
            'enabled_languages' => ['zh'],
            'default_language' => 'zh',

            // 三级分销
            'distribution_enabled' => false,
            'distribution_rate_l1' => 10,   // 百分比(10 = 10%)
            'distribution_rate_l2' => 5,
            'distribution_rate_l3' => 2,

            // 分站
            'subsite_enabled' => false,
            'subsite_default_confirm_days' => 7,
            'subsite_subdomain_base' => '',

            // 货源对接(spec §8.5) —— 网页可配,取代 .env 的 ZCARD_SUPPLY_*
            'supply_enabled' => false,            // 总开关(原 ZCARD_SUPPLY)
            'supply_upstream_enabled' => true,    // 作为下游拿货(原 ZCARD_SUPPLY_UPSTREAM)
            'supply_supplier_enabled' => true,    // 作为上游供货(原 ZCARD_SUPPLY_SUPPLIER)
            'supply_nonce_store' => 'cache',      // 防重放存储: cache|redis|database(原 ZCARD_SUPPLY_NONCE_STORE)
            'supply_rate_limit' => 60,            // 供货API限流: 每账号每分钟(原 ZCARD_SUPPLY_RATE_LIMIT)
            'supply_timestamp_skew' => 300,       // 签名时间窗口秒(原 ZCARD_SUPPLY_TS_SKEW)
        ];
    }

    /** 取全部配置(合并默认值),数组返回 */
    public static function all(): array
    {
        $rows = Setting::where('group', 'storefront')->pluck('value', 'key');
        $merged = self::defaults();
        foreach ($merged as $key => $default) {
            if (isset($rows[$key])) {
                $merged[$key] = $rows[$key];
            }
        }
        // value 列是 json cast,pluck 后可能是 array
        return $merged;
    }

    /** 取单个值 */
    public static function get(string $key): mixed
    {
        return self::all()[$key] ?? null;
    }

    /** 批量保存 */
    public static function setMany(array $kv): void
    {
        foreach ($kv as $key => $value) {
            Setting::updateOrCreate(
                ['key' => $key],
                ['value' => $value, 'group' => 'storefront']
            );
        }
    }
}
