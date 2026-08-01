<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SubsiteDomain extends Model
{
    protected $fillable = [
        'merchant_id', 'domain', 'type', 'verification_token',
        'verification_status', 'status', 'is_primary', 'verified_at',
    ];

    protected function casts(): array
    {
        return ['is_primary' => 'boolean', 'verified_at' => 'datetime'];
    }

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }
}
