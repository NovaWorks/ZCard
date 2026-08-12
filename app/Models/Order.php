<?php

namespace App\Models;

use App\Payment\Contracts\Payable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Order extends Model implements Payable
{
    /** 仅在创建请求生命周期内存在，绝不参与 Eloquent 持久化或序列化。 */
    private ?string $accessTokenForResponse = null;

    protected $fillable = [
        'order_no', 'merchant_id', 'user_id', 'product_id', 'quantity',
        'amount', 'base_currency', 'display_currency', 'exchange_rate', 'amount_display', 'coupon_code', 'discount_amount',
        'cost', 'sku_name', 'payment_channel',
        'status', 'delivery_status', 'fulfillment_type_snapshot', 'paid_at', 'closed_at',
        'contact', 'create_device', 'create_ip', 'extra', 'instructions_snapshot', 'delivery_message_snapshot',
        'subsite_id', 'subsite_domain', 'subsite_profit',
        // 供货上游来源(Phase 1)
        'source', 'upstream_order_id', 'upstream_source_id',
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

    public function setAccessTokenForResponse(string $token): self
    {
        $this->accessTokenForResponse = $token;

        return $this;
    }

    public function accessTokenForResponse(): ?string
    {
        return $this->accessTokenForResponse;
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(Review::class);
    }

    // ===== Payable 实现 =====

    public function getPayableKey(): string
    {
        return $this->order_no;
    }

    public function getPayableAmount(): int
    {
        return (int) $this->amount;
    }

    public function getPayableType(): string
    {
        return 'order';
    }
}
