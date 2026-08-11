<?php

namespace App\Models;

use App\Support\CardCipher;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Card extends Model
{
    const STATUS_UNUSED = 'unused';

    const STATUS_LOCKED = 'locked';

    const STATUS_USED = 'used';

    const STATUS_DISABLED = 'disabled';

    protected $fillable = [
        'product_id', 'import_id', 'content', 'content_hash',
        'status', 'order_id', 'locked_at', 'used_at',
        'note', 'card_type', 'owner_id', 'draft_premium', 'draft_cost',
        'price', 'number_hash', // 靓号自选:单价(分) / 靓号第一段 sha256(全局唯一)
    ];

    /** 密文/明文卡密与去重哈希默认永不进入模型 JSON。 */
    protected $hidden = ['content', 'content_hash'];

    protected function casts(): array
    {
        return [
            'locked_at' => 'datetime',
            'used_at' => 'datetime',
            'draft_premium' => 'integer',
            'draft_cost' => 'integer',
            'price' => 'integer',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function import(): BelongsTo
    {
        return $this->belongsTo(CardImport::class);
    }

    /** 关联订单(只读,展示 order_no) */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'order_id');
    }

    /** 取明文卡密（解密） */
    public function plainContent(): string
    {
        return CardCipher::decrypt($this->content);
    }

    /** 查看明文(同 plainContent,语义别名供 Filament modal 用) */
    public function decryptedContent(): string
    {
        return $this->plainContent();
    }
}
