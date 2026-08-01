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
            'host' => 'required|string',
            'port' => 'required|integer',
            'database' => 'required|string',
            'username' => 'required|string',
            'password' => 'nullable|string',
        ]);

        try {
            $dsn = "mysql:host={$data['host']};port={$data['port']};dbname={$data['database']};charset=utf8mb4";
            $pdo = new \PDO($dsn, $data['username'], $data['password'] ?? '', [\PDO::ATTR_TIMEOUT => 5]);
            $pdo = null; // 关闭连接

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
            'db_host' => 'required|string',
            'db_port' => 'required|integer',
            'db_database' => 'required|string',
            'db_username' => 'required|string',
            'db_password' => 'nullable|string',
            'admin_email' => 'required|email',
            'admin_password' => 'required|string|min:6',
        ]);

        try {
            // Step 1: 写入 .env
            $this->writeEnv('DB_HOST', $data['db_host']);
            $this->writeEnv('DB_PORT', $data['db_port']);
            $this->writeEnv('DB_DATABASE', $data['db_database']);
            $this->writeEnv('DB_USERNAME', $data['db_username']);
            if (! empty($data['db_password'])) {
                $this->writeEnv('DB_PASSWORD', $data['db_password']);
            }

            // Step 2: APP_KEY
            if (empty(config('app.key'))) {
                Artisan::call('key:generate');
            }

            // Step 3: 卡密加密密钥
            if (empty(config('zcard.card_encryption_key'))) {
                $key = 'base64:' . base64_encode(random_bytes(32));
                $this->writeEnv('CARD_ENCRYPTION_KEY', $key);
            }

            // Step 4: 清除配置缓存让新 .env 生效
            Artisan::call('config:clear');

            // Step 5: 迁移
            Artisan::call('migrate', ['--force' => true]);

            // Step 6: 角色
            foreach (['super_admin', 'merchant', 'user'] as $role) {
                \Spatie\Permission\Models\Role::firstOrCreate(['name' => $role]);
            }

            // Step 7: 管理员账号
            $admin = \App\Models\User::firstOrCreate(
                ['email' => $data['admin_email']],
                [
                    'username' => 'admin',
                    'name' => 'Super Admin',
                    'password' => $data['admin_password'],
                    'status' => 1,
                    'password_changed_at' => null,
                ]
            );
            $admin->assignRole('super_admin');

            // Step 8: 默认商户
            \App\Models\Merchant::firstOrCreate(
                ['slug' => 'default'],
                ['user_id' => $admin->id, 'name' => '默认商户', 'status' => 1, 'commission_rate' => 0]
            );

            // Step 9: 安装锁
            file_put_contents(storage_path('app/installed'), json_encode([
                'version' => config('app.version', '1.0.0'),
                'installed_at' => now()->toIso8601String(),
            ]));

            // Step 10: 缓存优化
            Artisan::call('config:cache');

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

    private function writeEnv(string $key, string $value): void
    {
        $path = base_path('.env');
        if (! file_exists($path)) {
            copy(base_path('.env.example'), $path);
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
