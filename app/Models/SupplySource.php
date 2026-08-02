<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class SupplySource extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'name', 'driver', 'base_url', 'credentials', 'status', 'settings',
        'last_synced_at', 'last_error', 'balance_cache', 'sort',
    ];

    protected function casts(): array
    {
        return [
            'credentials' => 'encrypted:array', // 加密存储的 json,自动加解密
            'settings' => 'array',
            'last_synced_at' => 'datetime',
            'balance_cache' => 'integer',
        ];
    }

    public const DRIVER_DUJIAO_NEXT = 'dujiao_next';
    public const DRIVER_ACG_FAKA = 'acg_faka';
    public const DRIVER_ZCARD = 'zcard';

    public function products(): HasMany
    {
        return $this->hasMany(Product::class, 'upstream_source_id');
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }
}
