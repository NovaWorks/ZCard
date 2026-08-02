<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class SupplierAccount extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'name', 'api_key', 'api_secret', 'balance', 'status', 'contact', 'remark',
    ];

    protected $hidden = ['api_secret'];

    protected function casts(): array
    {
        return [
            'balance' => 'integer',
        ];
    }

    public const STATUS_ACTIVE = 'active';
    public const STATUS_DISABLED = 'disabled';

    public function productPrices(): HasMany
    {
        return $this->hasMany(SupplierProductPrice::class);
    }

    public function ledgerEntries(): HasMany
    {
        return $this->hasMany(SupplierLedgerEntry::class);
    }

    public function supplyOrders(): HasMany
    {
        return $this->hasMany(SupplyOrder::class);
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }
}
