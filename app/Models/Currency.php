<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * 货币字典(spec §2.1)。code 为主键;is_base 全局唯一。
 */
class Currency extends Model
{
    public $incrementing = false;
    protected $keyType = 'string';
    protected $primaryKey = 'code';

    protected $fillable = [
        'code', 'name', 'symbol', 'symbol_position', 'decimal_places',
        'exchange_rate', 'is_base', 'is_enabled', 'sort',
    ];

    protected function casts(): array
    {
        return [
            'decimal_places' => 'integer',
            'exchange_rate' => 'decimal:8',
            'is_base' => 'boolean',
            'is_enabled' => 'boolean',
            'sort' => 'integer',
        ];
    }
}
