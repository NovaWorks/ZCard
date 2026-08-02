<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SupplierLedgerEntry extends Model
{
    protected $fillable = [
        'supplier_account_id', 'order_id', 'type', 'amount', 'balance_after',
        'idempotency_key', 'remark',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'balance_after' => 'integer',
        ];
    }

    public const TYPE_RECHARGE = 'recharge';
    public const TYPE_ORDER = 'order';
    public const TYPE_REFUND = 'refund';
    public const TYPE_ADJUST = 'adjust';

    public function supplierAccount(): BelongsTo
    {
        return $this->belongsTo(SupplierAccount::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
