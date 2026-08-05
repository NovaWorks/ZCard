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
        'recharge_no', 'user_id', 'amount', 'status', 'paid_at', 'target',
    ];

    /** 充值目标: balance=个人余额(默认), supply=供货余额 */
    public const TARGET_BALANCE = 'balance';
    public const TARGET_SUPPLY = 'supply';

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

    /**
     * 关闭超时未支付的充值单(pending → closed)。
     * 与 OrderService::closeExpired 配合,由 orders:close-expired 命令统一调度,
     * 避免 pending 充值单无限堆积(延迟回调也无法再入账)。
     *
     * @param int $expireMinutes 超时分钟数,默认与订单关单一致(后台 order_close_minutes)
     * @return int 关闭数量
     */
    public static function closeExpired(int $expireMinutes = 30): int
    {
        $cutoff = now()->subMinutes($expireMinutes);
        return static::where('status', self::STATUS_PENDING)
            ->where('created_at', '<', $cutoff)
            ->update(['status' => self::STATUS_CLOSED]);
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
