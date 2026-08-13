<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderDelivery extends Model
{
    protected $fillable = ['order_id', 'product_id', 'card_content', 'delivered_mode', 'delivered_at'];

    /** 安全(M-15):发货明文卡密不得因模型序列化意外进入接口响应。 */
    protected $hidden = ['card_content'];

    protected function casts(): array
    {
        return ['delivered_at' => 'datetime'];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
