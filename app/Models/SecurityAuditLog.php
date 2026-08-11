<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SecurityAuditLog extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'actor_id', 'source', 'action', 'target_type', 'target_id',
        'method', 'path', 'status_code', 'ip', 'user_agent', 'metadata', 'created_at',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'created_at' => 'datetime',
        ];
    }
}
