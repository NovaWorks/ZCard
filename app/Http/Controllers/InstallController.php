<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

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
                'message' => '连接失败: ' . $e->getMessage(),
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
                $key = 'base64:' . base64_encode(random_bytes(32));
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
                \Spatie\Permission\Models\Role::firstOrCreate(['name' => $role]);
            }

            // Step 7: 管理员账号(检查 email 或 username=admin 已存在)
            $admin = \App\Models\User::where('email', $data['admin_email'])->first();
            if (! $admin) {
                $admin = \App\Models\User::where('username', 'admin')->first();
            }
            if ($admin) {
                // 已存在,确保角色
                if (! $admin->hasRole('super_admin')) {
                    $admin->assignRole('super_admin');
                }
            } else {
                $admin = \App\Models\User::create([
                    'username' => 'admin',
                    'name' => 'Super Admin',
                    'email' => $data['admin_email'],
                    'password' => $data['admin_password'],
                    'status' => 1,
                    'password_changed_at' => null,
                ]);
                $admin->assignRole('super_admin');
            }

            // Step 8: 默认商户
            \App\Models\Merchant::firstOrCreate(
                ['slug' => 'default'],
                ['user_id' => $admin->id, 'name' => '默认商户', 'status' => 1, 'commission_rate' => 0]
            );

            // Step 9: 缓存优化(失败不中断安装)
            try {
                Artisan::call('config:cache');
            } catch (\Throwable $e) {
                // config:cache 失败不影响安装结果
            }

            // Step 10: 安装锁(最后一步,确保全部成功后才写)
            file_put_contents(storage_path('app/installed'), json_encode([
                'version' => \App\Support\AppHelper::version(),
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
                'message' => '安装失败: ' . $e->getMessage(),
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
            $value = '"' . str_replace('"', '\\"', $value) . '"';
        }
        $content = file_get_contents($path);
        $pattern = '/^' . preg_quote($key, '/') . '=.*/m';
        if (preg_match($pattern, $content)) {
            $content = preg_replace($pattern, "{$key}={$value}", $content);
        } else {
            $content .= "{$key}={$value}\n";
        }
        file_put_contents($path, $content);
    }
}
