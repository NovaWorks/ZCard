<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CardImport extends Model
{
    protected $fillable = [
        'product_id', 'operator_id', 'source', 'total',
        'success_count', 'failed_count', 'status', 'error_log',
    ];

    protected function casts(): array
    {
        return ['error_log' => 'array'];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function cards(): HasMany
    {
        return $this->hasMany(Card::class);
    }
}
