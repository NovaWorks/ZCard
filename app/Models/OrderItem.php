<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderItem extends Model
{
    protected $fillable = ['order_id', 'product_id', 'amount'];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
