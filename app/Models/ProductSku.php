<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductSku extends Model
{
    protected $fillable = [
        'product_id', 'name', 'price', 'stock_type', 'sort', 'status',
    ];

    protected function casts(): array
    {
        return ['status' => 'boolean'];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
