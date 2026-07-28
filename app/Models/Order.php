<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Order extends Model
{
    protected $fillable = [
        'order_no', 'merchant_id', 'user_id', 'product_id', 'quantity',
        'amount', 'status', 'paid_at', 'closed_at', 'contact', 'extra',
    ];

    protected function casts(): array
    {
        return ['paid_at' => 'datetime', 'closed_at' => 'datetime', 'extra' => 'array'];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }
}
