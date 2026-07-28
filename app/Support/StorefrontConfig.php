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
            'show_reviews' => false,
            'allow_post_review' => true,
            'review_need_audit' => true,
            'show_featured' => true,
            'featured_count' => 8,
            'show_hot_tags' => true,
            'hot_tag_categories' => [],
            'order_query_password' => true,
            'trade_captcha' => true,
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
