<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Support\AppHelper;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;

/**
 * Web 安装向导(无需登录,安装前访问)。
 * 前端为 storefront 风格的步骤向导(Tailwind)。
 *
 * 流程: 环境检查 → 数据库配置 → 管理员账号 → 执行安装
 */
class InstallController extends Controller
{
    /** 检查是否已安装 */
    private function isInstalled(): bool
    {
        return file_exists(storage_path('app/installed'));
    }

    /**
     * 环境检查 + 安装状态
     * GET /api/install/status
     */
    public function status(): JsonResponse
    {
        $installed = $this->isInstalled();

        if ($installed) {
            return response()->json([
                'installed' => true,
                'message' => '系统已安装',
            ]);
        }

        $checks = [
            ['name' => 'PHP >= 8.3', 'passed' => version_compare(PHP_VERSION, '8.3.0', '>=')],
            ['name' => 'PDO MySQL', 'passed' => extension_loaded('pdo_mysql')],
            ['name' => 'mbstring', 'passed' => extension_loaded('mbstring')],
            ['name' => 'OpenSSL', 'passed' => extension_loaded('openssl')],
            ['name' => 'bcmath', 'passed' => extension_loaded('bcmath')],
            ['name' => 'JSON', 'passed' => extension_loaded('json')],
            ['name' => 'cURL', 'passed' => extension_loaded('curl')],
            ['name' => 'GD / Imagick', 'passed' => extension_loaded('gd') || extension_loaded('imagick')],
            ['name' => 'Redis (可选)', 'passed' => extension_loaded('redis'), 'optional' => true],
        ];

        $writable = [
            ['name' => 'storage/', 'passed' => is_writable(storage_path())],
            ['name' => 'storage/app/', 'passed' => is_writable(storage_path('app'))],
            ['name' => 'bootstrap/cache/', 'passed' => is_writable(base_path('bootstrap/cache'))],
        ];

        $allPassed = collect($checks)->where('optional', '!=', true)->every('passed')
            && collect($writable)->every('passed');

        return response()->json([
            'installed' => false,
            'php_version' => PHP_VERSION,
            'checks' => $checks,
            'writable' => $writable,
            'all_passed' => $allPassed,
        ]);
    }

    /**
     * 测试数据库连接
     * POST /api/install/test-db
     */
    public function testDb(Request $request): JsonResponse
    {
        $data = $request->validate([
            'host' => ['required', 'string', 'regex:/^[a-zA-Z0-9._\-.:]+$/'],
            'port' => 'required|integer|between:1,65535',
            'database' => ['required', 'string', 'regex:/^[a-zA-Z0-9_]+$/'],
            'username' => 'required|string|max:100',
            'password' => 'nullable|string',
        ]);

        try {
            $dsn = "mysql:host={$data['host']};port={$data['port']};dbname={$data['database']};charset=utf8mb4";
            $options = [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION];
            // MySQL 连接超时(正确的常量)
            if (defined('PDO::MYSQL_ATTR_CONNECT_TIMEOUT')) {
                $options[\PDO::MYSQL_ATTR_CONNECT_TIMEOUT] = 5;
            }
            $pdo = new \PDO($dsn, $data['username'], $data['password'] ?? '', $options);
            $pdo = null;

            return response()->json(['success' => true, 'message' => '数据库连接成功']);
        } catch (\PDOException $e) {
            return response()->json([
                'success' => false,
                'message' => '连接失败: '.$e->getMessage(),
            ], 422);
        }
    }

    /**
     * 执行安装
     * POST /api/install/run
     */
    public function run(Request $request): JsonResponse
    {
        if ($this->isInstalled()) {
            return response()->json(['message' => '系统已安装,如需重新安装请删除 storage/app/installed 文件'], 403);
        }

        $data = $request->validate([
            'db_host' => ['required', 'string', 'regex:/^[a-zA-Z0-9._\-.:]+$/'],
            'db_port' => 'required|integer|between:1,65535',
            'db_database' => ['required', 'string', 'regex:/^[a-zA-Z0-9_]+$/'],
            'db_username' => 'required|string|max:100',
            'db_password' => 'nullable|string',
            'admin_email' => 'required|email',
            'admin_password' => 'required|string|min:6',
        ]);

        try {
            // Step 0: 修复关键目录权限(composer install 用 root 跑时,目录属主是 root,
            // 而 PHP-FPM 以 www 用户运行 → 不可写 → 500)。这里尝试自动 chmod 修复。
            $this->fixPermissions();

            // Step 1: 写入 .env(带引号保护特殊字符)
            $this->writeEnv('DB_HOST', $data['db_host']);
            $this->writeEnv('DB_PORT', (string) $data['db_port']);
            $this->writeEnv('DB_DATABASE', $data['db_database']);
            $this->writeEnv('DB_USERNAME', $data['db_username']);
            $this->writeEnv('DB_PASSWORD', $data['db_password'] ?? '');

            // Step 2: APP_KEY
            if (empty(config('app.key'))) {
                Artisan::call('key:generate');
            }

            // Step 3: 卡密加密密钥
            if (empty(env('CARD_ENCRYPTION_KEY'))) {
                $key = 'base64:'.base64_encode(random_bytes(32));
                $this->writeEnv('CARD_ENCRYPTION_KEY', $key);
            }

            // Step 4: 关键! 刷新进程内 DB 配置(让新 .env 的数据库凭据生效)
            // config:clear 只删缓存文件,不刷新进程内 config repository
            config([
                'database.connections.mysql.host' => $data['db_host'],
                'database.connections.mysql.port' => $data['db_port'],
                'database.connections.mysql.database' => $data['db_database'],
                'database.connections.mysql.username' => $data['db_username'],
                'database.connections.mysql.password' => $data['db_password'] ?? '',
            ]);
            DB::purge('mysql');
            DB::reconnect('mysql');

            // Step 5: 迁移
            Artisan::call('migrate', ['--force' => true]);

            // Step 6: 角色
            foreach (['super_admin', 'merchant', 'user'] as $role) {
                Role::firstOrCreate(['name' => $role]);
            }

            // Step 6.5: storage 公开链接(素材/上传图片经 /storage/ 访问必需)
            try {
                Artisan::call('storage:link');
            } catch (\Throwable $e) {
                // 链接已存在或权限不足时忽略,不影响安装
            }

            // Step 7: 管理员账号
            // 注意:User 用 SoftDeletes,User::where/firstOrCreate 会漏掉软删除记录 → 撞
            // username/email 的 unique 约束(1062)。故用 DB 门面直接操作(查询含软删除记录)。
            //
            // 全新安装时,migrate 阶段的 seed_default_payment_channels 迁移会先创建一个占位
            // admin(密码 admin123456),这里必须用客户输入的密码覆盖,否则客户登录失败。
            $now = now();
            $adminRow = DB::table('users')->where('username', 'admin')->first();
            if (! $adminRow) {
                $adminRow = DB::table('users')->where('email', $data['admin_email'])->first();
            }
            if ($adminRow) {
                // 已存在(含迁移预置的占位 admin 或软删除记录)→ 用客户输入的密码覆盖,
                // 并恢复为正常状态(清除软删除标记)。
                DB::table('users')->where('id', $adminRow->id)->update([
                    'username' => 'admin',
                    'email' => $data['admin_email'],
                    'password' => Hash::make($data['admin_password']),
                    'name' => 'Super Admin',
                    'status' => 1,
                    'deleted_at' => null,
                    'password_changed_at' => null,
                    'updated_at' => $now,
                ]);
                $adminId = $adminRow->id;
            } else {
                $adminId = DB::table('users')->insertGetId([
                    'username' => 'admin',
                    'email' => $data['admin_email'],
                    'password' => Hash::make($data['admin_password']),
                    'name' => 'Super Admin',
                    'status' => 1,
                    'password_changed_at' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
            // 确保 super_admin 角色(用 Eloquent 实例绑定 Spatie 角色)
            $admin = User::find($adminId);
            if ($admin && ! $admin->hasRole('super_admin')) {
                $admin->assignRole('super_admin');
            }

            // Step 8: 默认商户(DB 门面,幂等,避免软删除冲突)
            if (! DB::table('merchants')->where('slug', 'default')->exists()) {
                DB::table('merchants')->insert([
                    'user_id' => $adminId,
                    'name' => '默认商户',
                    'slug' => 'default',
                    'status' => 1,
                    'commission_rate' => 0,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            // Step 9: 缓存优化(失败不中断安装)
            try {
                Artisan::call('config:cache');
            } catch (\Throwable $e) {
                // config:cache 失败不影响安装结果
            }

            // Step 10: 安装锁(最后一步,确保全部成功后才写)
            file_put_contents(storage_path('app/installed'), json_encode([
                'version' => AppHelper::version(),
                'installed_at' => now()->toIso8601String(),
            ]));

            return response()->json([
                'success' => true,
                'message' => '安装完成',
                'admin_url' => url('/admin'),
            ]);

        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => '安装失败: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * 修复关键目录权限。
     * 场景:composer install / git pull 用 root 执行,新生成的 storage、
     * bootstrap/cache 目录属主为 root,而 PHP-FPM 以 www 用户运行 → 不可写
     * → Laravel 写 session/日志/编译视图时崩溃 → 500。
     * 此方法尝试递归 chmod 775 修复;若当前进程无权(chown 需要 root),至少
     * chmod 能生效(同组可写)。chown 仍需服务器手动执行(见部署指南)。
     */
    private function fixPermissions(): void
    {
        $dirs = [
            storage_path(),
            storage_path('app'),
            storage_path('app/public'),
            storage_path('framework'),
            storage_path('framework/cache'),
            storage_path('framework/cache/data'),
            storage_path('framework/sessions'),
            storage_path('framework/views'),
            storage_path('logs'),
            base_path('bootstrap/cache'),
        ];

        foreach ($dirs as $dir) {
            if (! is_dir($dir)) {
                @mkdir($dir, 0775, true);
            }
            @chmod($dir, 0775);
        }
    }

    /**
     * 写入 .env(带引号保护含特殊字符的值)。
     */
    private function writeEnv(string $key, string $value): void
    {
        $path = base_path('.env');
        if (! file_exists($path)) {
            copy(base_path('.env.example'), $path);
        }
        // 值含特殊字符时加双引号(符合 vlucas/phpdotenv 规范)
        if (preg_match('/[\s#=]/', $value) || $value === '') {
            $value = '"'.str_replace('"', '\\"', $value).'"';
        }
        $content = file_get_contents($path);
        $pattern = '/^'.preg_quote($key, '/').'=.*/m';
        if (preg_match($pattern, $content)) {
            $content = preg_replace($pattern, "{$key}={$value}", $content);
        } else {
            $content .= "{$key}={$value}\n";
        }
        file_put_contents($path, $content);
    }
}
