<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserGroup extends Model
{
    protected $table = 'user_groups';

    protected $fillable = [
        'name',
        'discount',
        'min_recharge',
        'sort',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'status' => 'boolean',
            'discount' => 'decimal:2',
            'min_recharge' => 'decimal:2',
        ];
    }
}
