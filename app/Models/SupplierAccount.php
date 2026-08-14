<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class SupplierAccount extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'user_id', 'name', 'api_key', 'api_secret', 'balance', 'status', 'approved', 'contact', 'remark',
    ];

    // api_secret 由服务层 Crypt::encryptString 加密存储,中间件手动解密(不用 cast 避免双重加密)
    protected $hidden = ['api_secret'];

    protected function casts(): array
    {
        return [
            'balance' => 'integer',
            'approved' => 'boolean',
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

    /** 审核通过才允许调用供货 API(自助开通默认待审核,管理员审核或后台开启自动通过) */
    public function isApproved(): bool
    {
        return $this->approved;
    }
}
