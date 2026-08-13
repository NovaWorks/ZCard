<?php

namespace App\Models;

use App\Support\HtmlContentSanitizer;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Product extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'merchant_id',
        // 供货上游来源(Phase 1)
        'upstream_source_id', 'upstream_product_code', 'upstream_synced_at', 'stock_cache',
        'category_id', 'name', 'slug', 'description', 'price',
        // SEO(自定义标题/关键词/描述,留空前端自动组合)
        'seo_title', 'seo_keywords', 'seo_description',
        'factory_price', 'draft_premium',
        'member_price', 'cover', 'images', 'stock_type', 'fulfillment_type', 'stock_visible', 'price_manual', 'upstream_price',
        'control_config', 'delivery_mode', 'sort', 'status',
        // P1-A 新增
        'is_featured', 'virtual_sales', 'virtual_reviews', 'min_order', 'max_order',
        // 商品扩展字段
        'contact_type', 'send_email', 'delivery_message', 'leave_message',
        'only_user', 'purchase_limit', 'hide', 'level_disable', 'dedup',
        // 购买选择方式(general 常规 / premium 靓号自选)
        'pick_type',
    ];

    public const FULFILLMENT_AUTO_CARD = 'auto_card';

    public const FULFILLMENT_FIXED = 'fixed';

    public const FULFILLMENT_MANUAL = 'manual';

    public const FULFILLMENT_UPSTREAM = 'upstream';

    protected function casts(): array
    {
        return [
            'member_price' => 'array',
            'images' => 'array',
            'control_config' => 'array',
            'virtual_reviews' => 'array',
            'upstream_synced_at' => 'datetime',
            'factory_price' => 'integer',
            'draft_premium' => 'integer',
            'stock_visible' => 'boolean',
            'price_manual' => 'boolean',
            'is_featured' => 'boolean',
            'send_email' => 'boolean',
            'only_user' => 'boolean',
            'hide' => 'boolean',
            'level_disable' => 'boolean',
            'dedup' => 'boolean',
        ];
    }

    /** 历史数据与新写入数据均在模型边界清理，避免存储型 XSS。 */
    protected function description(): Attribute
    {
        return Attribute::make(
            get: fn ($value) => HtmlContentSanitizer::sanitize($value),
            set: fn ($value) => HtmlContentSanitizer::sanitize($value),
        );
    }

    /** 付款后使用说明同样属于不可信富文本，必须在模型边界清理。 */
    protected function leaveMessage(): Attribute
    {
        return Attribute::make(
            get: fn ($value) => HtmlContentSanitizer::sanitize($value),
            set: fn ($value) => HtmlContentSanitizer::sanitize($value),
        );
    }

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function skus(): HasMany
    {
        return $this->hasMany(ProductSku::class);
    }

    /** 上游货源(同步来源) */
    public function upstreamSource(): BelongsTo
    {
        return $this->belongsTo(SupplySource::class, 'upstream_source_id');
    }

    public function cards(): HasMany
    {
        return $this->hasMany(Card::class);
    }

    /** 可用库存:固定内容/人工发货不限量;上游读缓存;自动卡密数 unused cards。 */
    public function availableStock(): int
    {
        $type = $this->resolvedFulfillmentType();

        if (in_array($type, [self::FULFILLMENT_FIXED, self::FULFILLMENT_MANUAL], true)) {
            return -1;
        }

        if ($type === self::FULFILLMENT_UPSTREAM) {
            return $this->stock_cache !== null ? (int) $this->stock_cache : -1;
        }

        return (int) $this->cards()->where('status', Card::STATUS_UNUSED)->count();
    }

    public function resolvedFulfillmentType(): string
    {
        if ($this->upstream_source_id) {
            return self::FULFILLMENT_UPSTREAM;
        }

        return $this->fulfillment_type ?: self::FULFILLMENT_AUTO_CARD;
    }

    /** 展示销量 = 真实销量 + 虚拟销量。真实销量留 P1-C(暂为0)。 */
    public function displaySales(): int
    {
        // P1-C 后:真实销量 = paid 订单 quantity 之和。P1-A 阶段真实=0。
        return $this->virtual_sales;
    }
}
