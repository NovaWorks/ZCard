<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 货源同步任务(异步入库 + 进度跟踪 + 取消)。
 */
class SupplySyncTask extends Model
{
    public const UPDATED_AT = null;

    public const STATUS_QUEUED = 'queued';

    public const STATUS_RUNNING = 'running';

    public const STATUS_SUCCESS = 'success';

    public const STATUS_FAILED = 'failed';

    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'supply_source_id', 'mode', 'status', 'total_products', 'processed_products',
        'created_count', 'updated_count', 'price_updated_count', 'hidden_count', 'error',
        'started_at', 'finished_at',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    public function source(): BelongsTo
    {
        return $this->belongsTo(SupplySource::class, 'supply_source_id');
    }

    public function isRunning(): bool
    {
        return in_array($this->status, [self::STATUS_QUEUED, self::STATUS_RUNNING], true);
    }
}
