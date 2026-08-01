<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 分销佣金记录(spec 阶段一)。按毛利 × 层级费率计算。
 */
class Commission extends Model
{
    protected $fillable = [
        'order_id', 'buyer_id', 'referrer_id', 'tier',
        'rate', 'base_amount', 'amount', 'status',
    ];

    protected function casts(): array
    {
        return [
            'tier' => 'integer',
            'rate' => 'decimal:4',
            'base_amount' => 'integer',
            'amount' => 'integer',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function buyer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'buyer_id');
    }

    public function referrer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'referrer_id');
    }
}
