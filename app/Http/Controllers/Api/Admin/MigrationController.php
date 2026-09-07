<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Card;
use App\Models\Setting;
use App\Support\AppHelper;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/**
 * 1.x → 2.0 数据迁移·源端控制台（《数据迁移工具开发计划》PHP 侧交付）。
 *
 * 定位：本站（1.x）是「被迁移的源端」，真正搬数据由 2.0 的
 * `zcard migrate-from-v1` 子命令执行（Go 二进制，通常部署在 2.0 服务器上）。
 * 本控制器提供源端视角的三件事：
 *  1. preflight —— 迁移前自检（密钥可用性/卡密抽样/schema 版本/规模/在途单）；
 *  2. command  —— 生成在 2.0 侧执行的命令与切换清单；
 *  3. keys     —— 导出密钥包（.env 形态，供 --old-env 使用；仅管理员可下载）。
 */
class MigrationController extends Controller
{
    /**
     * 源端自检（只读，不改任何数据）。
     */
    public function preflight(): JsonResponse
    {
        $checks = [];

        // 1. 密钥：APP_KEY / 卡密钥匙（不回传明文，只报状态与来源）
        $appKey = (string) config('app.key');
        $appKeyOk = str_starts_with($appKey, 'base64:') && strlen($appKey) > 20;
        $checks[] = [
            'name' => 'APP_KEY',
            'status' => $appKeyOk ? 'ok' : 'fail',
            'message' => $appKeyOk
                ? 'APP_KEY 已配置（base64 形态）——加密凭据可解密'
                : 'APP_KEY 缺失或形态异常——上游凭据/渠道配置将无法迁移',
        ];

        [$cardKeyStatus, $cardKeyFrom] = $this->cardKeyStatus();
        $checks[] = [
            'name' => '卡密加密钥匙',
            'status' => $cardKeyStatus === 'missing' ? 'warn' : 'ok',
            'message' => match ($cardKeyStatus) {
                'settings' => "已从后台配置解析（settings.card_encryption_key，$cardKeyFrom）",
                'env' => '取自 .env 的 CARD_ENCRYPTION_KEY',
                'missing' => '未配置卡密钥匙（若存量卡密为密文形态将无法迁移；全明文库存可忽略）',
            },
        ];

        // 2. 卡密抽样：形态统计 + 解密验证（复用 CardCipher，非 strict 语义）
        $cards = ['sampled' => 0, 'encrypted' => 0, 'plaintext' => 0, 'failed' => 0];
        try {
            $sample = Card::query()->orderBy('id')->limit(10)->pluck('content');
            foreach ($sample as $content) {
                $cards['sampled']++;
                if (! $this->looksEncrypted((string) $content)) {
                    $cards['plaintext']++;
                    continue;
                }
                $cards['encrypted']++;
                try {
                    \App\Support\CardCipher::decrypt((string) $content);
                } catch (\Throwable) {
                    $cards['failed']++;
                }
            }
        } catch (\Throwable $e) {
            $checks[] = ['name' => '卡密抽样', 'status' => 'warn', 'message' => '抽样失败：'.$e->getMessage()];
        }
        if ($cards['sampled'] > 0) {
            $checks[] = [
                'name' => '卡密抽样',
                'status' => $cards['failed'] > 0 ? 'fail' : 'ok',
                'message' => sprintf(
                    '抽样 %d 条：密文 %d（解密失败 %d）、明文 %d',
                    $cards['sampled'], $cards['encrypted'], $cards['failed'], $cards['plaintext']
                ).($cards['failed'] > 0 ? '——疑似密钥错配，请先在「卡密加密钥匙」项排查' : ''),
            ];
        }

        // 3. schema 版本探测：「元→分」改造（2026_08_02 迁移）后金额列必须为整型
        if (DB::connection()->getDriverName() === 'mysql') {
            $old = false;
            foreach ([['user_groups', 'min_recharge'], ['user_groups', 'min_consumption'], ['cards', 'draft_premium'], ['cards', 'draft_cost']] as [$t, $c]) {
                $type = DB::selectOne(
                    'SELECT DATA_TYPE AS t FROM information_schema.COLUMNS '
                    .'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
                    [$t, $c]
                )->t ?? null;
                if ($type !== null && ! in_array($type, ['tinyint', 'smallint', 'mediumint', 'int', 'bigint'], true)) {
                    $old = true;
                }
            }
            $checks[] = [
                'name' => '数据库版本',
                'status' => $old ? 'fail' : 'ok',
                'message' => $old
                    ? '金额列仍为小数类型——本库停在 v1.9 之前的旧结构，请先升级 1.x 再迁移'
                    : '金额列为整型（分），满足迁移要求',
            ];
        } else {
            $checks[] = ['name' => '数据库版本', 'status' => 'info', 'message' => '当前为非 MySQL 驱动（开发环境），生产切换前请在 MySQL 上复检'];
        }

        // 4. 规模与在途（切换窗口规划依据）
        $stats = [];
        foreach (['users', 'products', 'cards', 'orders', 'payments', 'bills'] as $t) {
            try {
                $stats[$t] = DB::table($t)->count();
            } catch (\Throwable) {
                $stats[$t] = null;
            }
        }
        try {
            $pendingOrders = DB::table('orders')->where('status', 'pending')->count();
            $pendingPayments = DB::table('payments')->where('status', 'pending')->count();
        } catch (\Throwable) {
            $pendingOrders = $pendingPayments = null;
        }
        if ($pendingOrders !== null) {
            $checks[] = [
                'name' => '在途订单',
                'status' => ($pendingOrders + $pendingPayments) > 0 ? 'warn' : 'ok',
                'message' => ($pendingOrders + $pendingPayments) > 0
                    ? "待付款订单 {$pendingOrders} 笔 / 待确认支付 {$pendingPayments} 笔——切换窗口前应等待其关闭或人工处理"
                    : '无在途订单/支付',
            ];
        }

        // 5. 软删除提示（迁移会带上软删数据，站长应知情）
        try {
            $trashed = [
                'users' => DB::table('users')->whereNotNull('deleted_at')->count(),
                'products' => DB::table('products')->whereNotNull('deleted_at')->count(),
            ];
        } catch (\Throwable) {
            $trashed = null;
        }

        $ready = collect($checks)->every(fn ($c) => $c['status'] !== 'fail');

        return response()->json([
            'version' => AppHelper::version(),
            'ready' => $ready,
            'checks' => $checks,
            'cards' => $cards,
            'stats' => $stats,
            'pending' => ['orders' => $pendingOrders, 'payments' => $pendingPayments],
            'trashed' => $trashed,
            'keys' => ['app_key' => $appKeyOk, 'card_key' => $cardKeyStatus],
        ]);
    }

    /**
     * 生成 2.0 侧执行命令与切换清单（不含任何密钥明文）。
     */
    public function command(): JsonResponse
    {
        return response()->json([
            'steps' => [
                ['title' => '1. 准备 2.0 环境', 'detail' => '在 2.0 服务器安装 zcard 并完成 /install 初始化（目标库建议与 1.x 不同库，切换前 1.x 保持只读）。'],
                ['title' => '2. 下载密钥包', 'detail' => '点击「下载密钥包」得到 zcard-v1-migrate.env，复制到 2.0 服务器（如 /opt/zcard1.env），权限 600，迁移完成后删除。'],
                ['title' => '3. 演练（dry-run）', 'detail' => '先在测试目标库完整演练：dry-run 预检 → 实跑 → 校验报告全绿。至少演练 2 次并记录耗时。'],
                ['title' => '4. 切换窗口', 'detail' => '公告 → 1.x 开维护模式停写 → 等待在途订单关闭 → 终次执行（幂等，只补新增）→ 校验 → 切流量到 2.0。'],
                ['title' => '5. 回滚预案', 'detail' => '窗口内保留 1.x 只读快照；回滚 = 入口切回 1.x。密钥包与两把密钥离线归档（丢失=卡密/凭据永久不可迁）。'],
            ],
            'commands' => [
                'dry_run' => 'zcard migrate-from-v1 --old-env /opt/zcard1.env --conf configs --dry-run',
                'run' => 'zcard migrate-from-v1 --old-env /opt/zcard1.env --conf configs',
                'phases_main_first' => 'zcard migrate-from-v1 --old-env /opt/zcard1.env --conf configs --phase 0-5',
                'verify' => 'zcard migrate-from-v1 --conf configs --verify-only',
            ],
            'notes' => [
                '主站先行、分站分批：默认全阶段执行；分站可先用 --phase 0-5，分站域（P6）分批补跑。',
                '迁移全程只读 1.x 数据库；生产建议为 2.0 工具配置只读账号。',
                '已完成迁移的行可幂等重跑（自动跳过），中断续跑安全。',
            ],
        ]);
    }

    /**
     * 导出密钥包（.env 形态，供 2.0 工具 --old-env 使用）。
     * 内容含 APP_KEY / 卡密钥匙 / 时区 / 数据库连接——仅管理员（admin.role 中间件）可下载，
     * 下载行为已被 audit.admin 审计。
     */
    public function keys(): JsonResponse
    {
        [$cardKeyStatus, $cardKey] = $this->cardKeyStatus(includeMaterial: true);
        if ($cardKeyStatus === 'missing') {
            $cardKey = (string) config('zcard.card_encryption_key');
        }

        $conn = config('database.connections.'.(string) config('database.default'), []);
        $lines = [
            '# ZCard 1.x → 2.0 迁移密钥包（由管理后台导出）',
            '# 用法：复制到 2.0 服务器，执行 zcard migrate-from-v1 --old-env <本文件目录>',
            '# 警告：内含数据库凭据与加密密钥，请设 600 权限并在迁移完成后删除',
            'APP_KEY='.(string) config('app.key'),
        ];
        if ($cardKey !== '') {
            $lines[] = 'CARD_ENCRYPTION_KEY='.$cardKey;
        }
        $lines[] = 'APP_TIMEZONE='.(string) config('app.timezone');
        foreach ([
            'DB_HOST' => $conn['host'] ?? '',
            'DB_PORT' => (string) ($conn['port'] ?? 3306),
            'DB_DATABASE' => $conn['database'] ?? '',
            'DB_USERNAME' => $conn['username'] ?? '',
            'DB_PASSWORD' => $conn['password'] ?? '',
        ] as $k => $v) {
            $lines[] = $k.'='.($v !== '' ? '"'.$v.'"' : '');
        }

        return response()->json([
            'filename' => 'zcard-v1-migrate.env',
            'content' => implode("\n", $lines)."\n",
            'card_key_source' => $cardKeyStatus,
        ]);
    }

    /**
     * 卡密钥匙状态（与 App\Support\CardCipher::resolveKey 同一条解析链）。
     * 返回 [状态(settings|env|missing), 钥匙串或来源说明]。
     */
    private function cardKeyStatus(bool $includeMaterial = false): array
    {
        $cfgKey = Setting::query()->where('key', 'card_encryption_key')->value('value');
        if ($cfgKey) {
            try {
                $material = (string) Crypt::decryptString((string) $cfgKey);

                return ['settings', $includeMaterial ? $material : 'Crypt 密文形态'];
            } catch (\Throwable) {
                if ($includeMaterial) {
                    return ['settings', (string) $cfgKey]; // 历史明文形态
                }

                return ['settings', '历史明文形态（建议在后台重存为密文）'];
            }
        }
        $env = (string) config('zcard.card_encryption_key');
        if ($env !== '') {
            return ['env', $includeMaterial ? $env : 'env'];
        }

        return ['missing', null];
    }

    /**
     * 密文形态识别（与 CardCipher::looksEncrypted 同口径，private 故本地复制）。
     */
    private function looksEncrypted(string $cipher): bool
    {
        $decoded = base64_decode($cipher, true);
        if ($decoded === false || $decoded === '' || $cipher === '') {
            return false;
        }
        $json = json_decode($decoded, true);

        return is_array($json) && isset($json['iv'], $json['value'], $json['mac']);
    }
}
