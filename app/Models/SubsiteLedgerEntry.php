<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
class SubsiteLedgerEntry extends Model
{
    protected $fillable = ['merchant_id', 'order_id', 'type', 'amount', 'status', 'available_at', 'withdraw_request_id', 'idempotency_key', 'remark'];
    protected function casts(): array
    {
        return ['amount' => 'integer', 'available_at' => 'datetime'];
    }
    public function merchant(): BelongsTo { return $this->belongsTo(Merchant::class); }
    public function order(): BelongsTo { return $this->belongsTo(Order::class); }
}
