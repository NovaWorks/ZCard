<?php

namespace App\Support;

use App\Jobs\SendAdminAlertJob;
use App\Models\SecurityAuditLog;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * 管理员登录异常检测与告警(issue #6)。
 *
 * 规则:
 * - 首次登录(该管理员没有任何历史登录记录)→ 只建立基线,不告警;
 * - 之后的登录:当前 IP 或设备指纹(UA)从未出现过 → 判定"陌生登录"并发告警;
 * - 告警异步发送,失败静默。
 */
class AdminLoginAlertService
{
    /** 登录审计 action(与 AuthController 保持一致) */
    private const ACTION_LOGIN_SUCCESS = 'login.success';

    public static function checkAndAlert(Request $request, User $user): void
    {
        $ip = (string) $request->ip();
        $ua = (string) $request->userAgent();
        $uaHash = md5($ua);

        // 该管理员的历史登录记录(按时间正序)
        $history = SecurityAuditLog::where('actor_id', $user->id)
            ->where('action', self::ACTION_LOGIN_SUCCESS)
            ->orderBy('id')
            ->get(['ip', 'user_agent']);

        // 首次登录:建立基线,不告警
        if ($history->isEmpty()) {
            return;
        }

        $knownIps = $history->pluck('ip')->filter()->map(fn ($v) => (string) $v)->unique();
        $knownUaHashes = $history->pluck('user_agent')->filter()->map(fn ($v) => md5((string) $v))->unique();

        $isNewIp = ! $knownIps->contains($ip);
        $isNewDevice = ! $knownUaHashes->contains($uaHash);
        if (! $isNewIp && ! $isNewDevice) {
            return; // 常用 IP + 常用设备,不告警
        }

        $channels = AdminNotifier::activeChannels();
        if (empty($channels)) {
            return;
        }

        $deviceLabel = $isNewDevice ? '新设备' : '常用设备';
        $ipLabel = $isNewIp ? '陌生 IP' : '常用 IP';
        $siteName = (string) StorefrontConfig::get('site_name', 'ZCard');
        $time = now()->format('Y-m-d H:i:s');

        $subject = "【{$siteName}】管理员登录安全提醒";
        $content = implode("\n", [
            "【{$siteName}】管理员登录提醒",
            "账号: {$user->username} / {$user->email}",
            "时间: {$time}",
            "IP: {$ip} ({$ipLabel})",
            "设备: {$deviceLabel} (".mb_substr($ua, 0, 120).')',
            '如非本人操作,请立即修改密码并检查后台安全设置。',
        ]);

        SendAdminAlertJob::dispatch($channels, $subject, $content);
    }
}
