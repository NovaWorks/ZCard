<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Order extends Model
{
    protected $fillable = [
        'order_no', 'merchant_id', 'user_id', 'product_id', 'quantity',
        'amount', 'base_currency', 'display_currency', 'exchange_rate', 'amount_display', 'coupon_code', 'discount_amount',
        'cost', 'sku_name', 'payment_channel',
        'status', 'delivery_status', 'paid_at', 'closed_at',
        'contact', 'create_device', 'create_ip', 'extra',
        'subsite_id', 'subsite_domain', 'subsite_profit',
    ];

    protected function casts(): array
    {
        return ['paid_at' => 'datetime', 'closed_at' => 'datetime', 'extra' => 'array', 'exchange_rate' => 'decimal:8'];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function orderDeliveries(): HasMany
    {
        return $this->hasMany(OrderDelivery::class);
    }
}
