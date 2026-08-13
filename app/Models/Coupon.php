<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Coupon extends Model
{
    protected $fillable = [
        'code', 'type', 'value', 'product_id', 'category_id',
        'min_amount', 'status', 'expires_at', 'used_at', 'used_by',
        'order_id', 'note',
    ];

    protected function casts(): array
    {
        return [
            'value' => 'integer',
            'min_amount' => 'integer',
            'expires_at' => 'datetime',
            'used_at' => 'datetime',
        ];
    }

    public const STATUS_ACTIVE = 'active';

    public const STATUS_USED = 'used';

    public const STATUS_DISABLED = 'disabled';

    public const TYPE_FIXED = 'fixed';

    public const TYPE_PERCENT = 'percent';

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'used_by');
    }
}
