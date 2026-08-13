<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * 前台访问日志(PV/UV 统计,issue #6)。
 */
class VisitLog extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['ip', 'user_agent', 'path', 'created_at'];
}
