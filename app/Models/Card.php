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
    ];

    protected function casts(): array
    {
        return [
            'locked_at' => 'datetime',
            'used_at' => 'datetime',
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

    /** 取明文卡密（解密） */
    public function plainContent(): string
    {
        return CardCipher::decrypt($this->content);
    }
}
