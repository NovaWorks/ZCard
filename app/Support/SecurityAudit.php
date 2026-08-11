<?php

namespace App\Support;

use App\Models\SecurityAuditLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class SecurityAudit
{
    public static function record(
        Request $request,
        string $action,
        ?string $targetType = null,
        int|string|null $targetId = null,
        array $metadata = [],
        ?int $statusCode = null,
    ): void {
        try {
            SecurityAuditLog::create([
                'actor_id' => $request->user()?->id,
                'source' => 'admin_api',
                'action' => $action,
                'target_type' => $targetType,
                'target_id' => $targetId === null ? null : (string) $targetId,
                'method' => $request->method(),
                'path' => '/'.ltrim($request->path(), '/'),
                'status_code' => $statusCode,
                'ip' => $request->ip(),
                'user_agent' => mb_substr((string) $request->userAgent(), 0, 500),
                'metadata' => $metadata ?: null,
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
            // 审计写入失败不能改变业务响应，但必须进入服务日志。
            Log::error('安全审计写入失败: '.$e->getMessage());
        }
    }
}
