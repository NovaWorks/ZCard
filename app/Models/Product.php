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
        'merchant_id', 'category_id', 'name', 'slug', 'description', 'price',
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

    /** 可用卡密库存数(cards WHERE unused) */
    public function availableStock(): int
    {
        return (int) $this->cards()->where('status', Card::STATUS_UNUSED)->count();
    }

    /** 展示销量 = 真实销量 + 虚拟销量。真实销量留 P1-C(暂为0)。 */
    public function displaySales(): int
    {
        // P1-C 后:真实销量 = paid 订单 quantity 之和。P1-A 阶段真实=0。
        return $this->virtual_sales;
    }
}
