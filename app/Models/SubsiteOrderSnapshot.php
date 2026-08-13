<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SubsiteOrderSnapshot extends Model
{
    protected $fillable = ['order_id', 'merchant_id', 'domain', 'reseller_user_id', 'buyer_id', 'base_amount', 'reseller_amount', 'profit_amount', 'profit_eligible', 'profit_block_reason', 'pricing_snapshot', 'risk_snapshot'];

    protected function casts(): array
    {
        return ['base_amount' => 'integer', 'reseller_amount' => 'integer', 'profit_amount' => 'integer', 'profit_eligible' => 'boolean', 'pricing_snapshot' => 'array', 'risk_snapshot' => 'array'];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }
}
