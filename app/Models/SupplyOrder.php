<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SupplyOrder extends Model
{
    protected $fillable = [
        'supplier_account_id', 'order_id', 'downstream_order_no',
        'fulfillment_mode', 'callback_url', 'callback_status',
    ];

    public const MODE_SYNC = 'sync';
    public const MODE_ASYNC = 'async';

    public const CALLBACK_PENDING = 'pending';
    public const CALLBACK_SENT = 'sent';
    public const CALLBACK_FAILED = 'failed';

    public function supplierAccount(): BelongsTo
    {
        return $this->belongsTo(SupplierAccount::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
