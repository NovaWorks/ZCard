<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SubsiteProductSetting extends Model
{
    protected $fillable = [
        'merchant_id', 'product_id', 'sku_id', 'is_listed',
        'pricing_mode', 'markup_percent', 'fixed_markup_amount',
        'fixed_price_amount', 'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'sku_id' => 'integer',
            'is_listed' => 'boolean',
            'markup_percent' => 'decimal:2',
            'fixed_markup_amount' => 'integer',
            'fixed_price_amount' => 'integer',
            'sort_order' => 'integer',
        ];
    }

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
