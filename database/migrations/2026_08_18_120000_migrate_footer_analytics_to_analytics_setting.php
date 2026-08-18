<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * issue #39:把已弃用的 footer_analytics 存量数据搬入结构化的 analytics 配置。
 *
 * v1.12.55 起 footer_analytics 既不下发也不注入,但后台仍在收集,形成静默失效。
 * 迁移**保持 enabled=false**:旧脚本引用的域名未必在受信白名单内,必须由管理员
 * 在后台确认(必要时补白名单)后手动启用,不能在升级瞬间自动放宽 CSP 并执行远程脚本。
 */
return new class extends Migration
{
    public function up(): void
    {
        $legacy = DB::table('settings')
            ->where('group', 'storefront')
            ->where('key', 'footer_analytics')
            ->value('value');

        $script = trim((string) json_decode((string) $legacy, true));
        if ($script === '') {
            return;
        }

        $existing = DB::table('settings')
            ->where('key', 'analytics')
            ->value('value');
        $current = json_decode((string) $existing, true);

        // 已有结构化配置(且脚本非空)时不覆盖,避免二次升级把管理员新配置写回旧值。
        if (is_array($current) && trim((string) ($current['script'] ?? '')) !== '') {
            return;
        }

        // settings.key 是全局唯一索引,匹配条件只能用 key(带上 group 会在 group 不同的
        // 历史行上插入重复 key 而违反唯一约束)。
        DB::table('settings')->updateOrInsert(
            ['key' => 'analytics'],
            [
                'group' => 'storefront',
                'value' => json_encode(['enabled' => false, 'script' => $script]),
                'updated_at' => now(),
                'created_at' => now(),
            ],
        );
    }

    public function down(): void
    {
        // 迁移只做数据搬运,回滚不删除 analytics 配置(删除会让管理员已确认启用的统计代码丢失)。
    }
};
