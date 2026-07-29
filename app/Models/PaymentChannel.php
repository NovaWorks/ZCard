<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PaymentChannel extends Model
{
    protected $fillable = [
        'merchant_id', 'name', 'code', 'driver', 'config',
        'fee', 'fee_type', 'sort', 'enabled',
    ];

    protected function casts(): array
    {
        return [
            'config' => 'array',
            'enabled' => 'boolean',
        ];
    }
}
