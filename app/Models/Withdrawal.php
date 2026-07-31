<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Withdrawal extends Model
{
    protected $fillable = [
        'user_id', 'amount', 'actual_amount', 'fee', 'method',
        'account', 'account_name', 'status', 'reject_reason',
        'admin_id', 'processed_at',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'actual_amount' => 'integer',
            'fee' => 'integer',
            'processed_at' => 'datetime',
        ];
    }

    public const STATUS_PENDING = 'pending';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function admin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'admin_id');
    }
}
