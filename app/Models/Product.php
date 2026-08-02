<?php

namespace App\Models;

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
        'factory_price', 'draft_premium',
        'member_price', 'cover', 'images', 'stock_type', 'stock_visible',
        'control_config', 'delivery_mode', 'sort', 'status',
        // P1-A 新增
        'is_featured', 'virtual_sales', 'virtual_reviews', 'min_order', 'max_order',
        // 商品扩展字段
        'contact_type', 'send_email', 'delivery_message', 'leave_message',
        'only_user', 'purchase_limit', 'hide', 'level_disable', 'dedup',
    ];

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
            'is_featured' => 'boolean',
            'send_email' => 'boolean',
            'only_user' => 'boolean',
            'hide' => 'boolean',
            'level_disable' => 'boolean',
            'dedup' => 'boolean',
        ];
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

    public function cards(): HasMany
    {
        return $this->hasMany(Card::class);
    }

    /** 可用库存:上游商品读 stock_cache(无本地卡);本地商品数 unused cards */
    public function availableStock(): int
    {
        // 上游商品:读缓存的库存数(-1=无限)
        if ($this->upstream_source_id && $this->stock_cache !== null) {
            return (int) $this->stock_cache;
        }

        return (int) $this->cards()->where('status', Card::STATUS_UNUSED)->count();
    }

    /** 展示销量 = 真实销量 + 虚拟销量。真实销量留 P1-C(暂为0)。 */
    public function displaySales(): int
    {
        // P1-C 后:真实销量 = paid 订单 quantity 之和。P1-A 阶段真实=0。
        return $this->virtual_sales;
    }
}
