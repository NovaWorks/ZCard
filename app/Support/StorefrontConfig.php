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
            // 订单查询页常见问题(后台可配置,前台优先展示;空则回退前台内置文案)
            'order_query_faqs' => [
                ['q' => '下单后多久能收到卡密?', 'a' => '付款成功后系统自动发货,通常几秒内即可在订单中查看并复制卡密。如遇高峰可能略有延迟,请耐心刷新订单页面。'],
                ['q' => '找不到订单怎么办?', 'a' => '请确认输入的订单号或联系方式与下单时完全一致。使用邮箱或手机号可查询该联系方式下的全部历史订单。'],
                ['q' => '已付款但看不到卡密?', 'a' => '在订单卡片中点击「查看卡密」即可展开并复制。若订单显示已支付但仍无卡密内容,可能是库存补货中,请联系在线客服处理。'],
                ['q' => '订单各状态是什么意思?', 'a' => '待支付:订单已创建但未完成付款;已支付:付款成功,卡密已自动发货;已关闭:超时未支付,系统自动取消并释放库存。'],
                ['q' => '购买的卡密无法使用怎么办?', 'a' => '请先核对卡密是否完整复制(注意前后空格)。若确认卡密无效,请保留订单号和卡密截图,第一时间联系客服,核实后可补发或退款。'],
                ['q' => '可以申请退款吗?', 'a' => '虚拟商品具有可复制性,原则上卡密一经查看不支持无理由退款。如遇卡密本身质量问题,请联系客服核实处理。'],
            ],
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

            // 卡密加密(默认关闭:明文存储,正常导入;开启需填密钥,加密存储)
            'card_encryption_enabled' => false,
            // 卡密加密密钥(存 Crypt 密文;开启时必填;变更会导致已加密卡密无法解密)
            'card_encryption_key' => '',

            // 系统运维
            'maintenance_mode' => false,
            'maintenance_message' => '系统维护中,请稍后再来访问。',
            'site_notice' => '',

            // 站点与页脚信息(全前台通用,后台「店铺设置-站点信息」编辑)
            'site_name' => 'ZCard',
            'site_url' => '',
            'site_logo' => '',
            'site_description' => 'ZCard — 现代化插件制虚拟商品自动发卡平台,7×24 小时极速发货,安全可靠。',
            'site_keywords' => '虚拟商品,自动发卡,卡密,游戏账号,会员充值',
            // 顶部品牌条文案(后台可自定义;英文留空回退中文;中文留空回退前端 i18n 默认)
            'brand_slogan' => '全球领先的虚拟商品自动发卡平台 · 7×24 小时极速发货',
            'brand_slogan_en' => 'Leading virtual goods auto-delivery platform · 24/7 instant dispatch',
            'brand_secure' => '安全支付',
            'brand_secure_en' => 'Secure payment',
            'brand_privacy' => '隐私保护',
            'brand_privacy_en' => 'Privacy protected',
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
            // 帮助中心(页脚栏4):后台可配置的链接/文本列表
            // title 为默认语言标题;title_en 为英文标题(前台英文界面时显示,缺省回退 title)
            'footer_help_links' => [
                ['title' => '常见问题', 'title_en' => 'FAQ', 'url' => '/orders/query'],
                ['title' => '购买须知', 'title_en' => 'Purchase Guide', 'url' => ''],
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

            // 会员等级自动升级依据: recharge=按累计充值, consumption=按累计消费
            'member_upgrade_basis' => 'recharge',

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
        // 敏感密钥脱敏:不向任何调用方暴露 card_encryption_key 真实值(含前台 settings API)
        if (! empty($merged['card_encryption_key'])) {
            $merged['card_encryption_key'] = '••••••••';
        }

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
