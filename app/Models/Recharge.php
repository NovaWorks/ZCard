<?php

namespace App\Models;

use App\Payment\Contracts\Payable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 用户余额充值单。
 *
 * 与发卡订单(Order)解耦:充值单只用于"付款 → 入账余额",
 * 不涉及商品、卡密、库存。支付链路通过 Payable 接口与订单共用驱动。
 */
class Recharge extends Model implements Payable
{
    protected $fillable = [
        'recharge_no', 'user_id', 'amount', 'status', 'paid_at',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'paid_at' => 'datetime',
        ];
    }

    public const STATUS_PENDING = 'pending';
    public const STATUS_PAID = 'paid';
    public const STATUS_CLOSED = 'closed';

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    // ===== Payable 实现 =====

    public function getPayableKey(): string
    {
        return $this->recharge_no;
    }

    public function getPayableAmount(): int
    {
        return (int) $this->amount;
    }

    public function getPayableType(): string
    {
        return 'recharge';
    }
}
