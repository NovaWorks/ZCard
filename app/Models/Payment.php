<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Payment extends Model
{
    protected $fillable = [
        'order_id', 'channel', 'channel_order_no', 'amount', 'status', 'paid_at', 'raw',
        'charged_currency', 'charged_amount', 'channel_exchange_rate',
    ];

    protected function casts(): array
    {
        return [
            'paid_at' => 'datetime',
            'raw' => 'array',
            'channel_exchange_rate' => 'decimal:8',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
