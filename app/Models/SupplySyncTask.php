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

    public const STATUS_CANCELLING = 'cancelling';

    public const STATUS_SUCCESS = 'success';

    public const STATUS_FAILED = 'failed';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_TIMED_OUT = 'timed_out';

    public const SCOPE_COLLECT = 'collect';

    public const SCOPE_PRICE = 'price';

    public const SCOPE_STATUS = 'status';

    public const CANCEL_TRIGGER_ADMIN = 'admin';

    public const CANCEL_TRIGGER_SYSTEM = 'system';

    protected $fillable = [
        'supply_source_id', 'mode', 'scope', 'force_reprice', 'status', 'total_products', 'processed_products',
        'created_count', 'updated_count', 'price_updated_count', 'manual_price_skipped_count',
        'hidden_count', 'deleted_count', 'error', 'error_code', 'error_context',
        'started_at', 'heartbeat_at', 'current_stage', 'current_page', 'stage_current', 'stage_total',
        'cancel_requested_at', 'cancel_requested_by', 'cancel_requested_by_name',
        'cancel_request_ip', 'cancel_reason', 'cancel_trigger',
        'worker_version', 'finished_at',
    ];

    protected $casts = [
        'force_reprice' => 'boolean',
        'started_at' => 'datetime',
        'heartbeat_at' => 'datetime',
        'cancel_requested_at' => 'datetime',
        'finished_at' => 'datetime',
        'error_context' => 'array',
    ];

    public function source(): BelongsTo
    {
        return $this->belongsTo(SupplySource::class, 'supply_source_id');
    }

    public function isRunning(): bool
    {
        return in_array($this->status, [self::STATUS_QUEUED, self::STATUS_RUNNING, self::STATUS_CANCELLING], true);
    }
}
