<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SupplyNonce extends Model
{
    protected $fillable = ['nonce', 'expires_at'];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
        ];
    }
}
