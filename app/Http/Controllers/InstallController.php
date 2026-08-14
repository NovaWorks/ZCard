<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Support\AppHelper;
use App\Support\SecurityAudit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
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
     * 有效安装判定(安全审计 H-2):锁文件缺失时,若数据库中已存在启用的管理员账号,
     * 同样视为已安装——防止"锁文件丢失后匿名重跑安装、覆盖 admin 密码接管整站"。
     */
    private function isEffectivelyInstalled(): bool
    {
        if ($this->isInstalled()) {
            return true;
        }

        try {
            if (! Schema::hasTable('users')) {
                return false;
            }

            return User::query()->where('status', 1)->exists();
        } catch (\Throwable) {
            // 数据库不可用时按未安装处理(与 EnsureInstalled 的降级行为一致)。
            return false;
        }
    }

    /**
     * 安装来源防护(安全审计 M7 + M-6 收紧)。
     * 安装向导可创建超级管理员并重写 .env,未安装期间暴露公网 = 可被外部抢先接管。
     * - 配置 ZCARD_INSTALL_ALLOWED_IPS(逗号分隔,支持 IP 或 CIDR)→ 仅白名单可调安装接口;
     * - 未配置时默认仅允许回环地址(本机向导/SSH 隧道),公网部署需显式配置白名单;
     * - 每次安装尝试都写入安全审计日志。
     */
    private function assertInstallAllowed(Request $request): void
    {
        $allowed = trim((string) env('ZCARD_INSTALL_ALLOWED_IPS', ''));
        $ip = (string) $request->ip();

        if ($allowed === '') {
            // 默认拒绝公网(倒置安全):全新部署在完成安装前一旦公网可达,
            // 任何人可抢先 /install 接管整站。回环(本机/SSH 端口转发)始终放行。
            if ($ip === '127.0.0.1' || $ip === '::1' || $this->ipInCidr($ip, '127.0.0.0/8')) {
                SecurityAudit::record($request, 'install.allowed_loopback', InstallController::class);

                return;
            }
            SecurityAudit::record($request, 'install.blocked_default_loopback_only', InstallController::class);
            abort(403, '安装接口默认仅允许本机访问;公网安装请在 .env 配置 ZCARD_INSTALL_ALLOWED_IPS 白名单后重试');
        }

        foreach (array_map('trim', explode(',', $allowed)) as $entry) {
            if ($entry === '' || str_contains($entry, '/') === false) {
                if ($entry === $ip) {
                    return;
                }

                continue;
            }
            if ($this->ipInCidr($ip, $entry)) {
                return;
            }
        }

        SecurityAudit::record($request, 'install.blocked_not_in_whitelist', InstallController::class);
        abort(403, '安装向导已限制为特定来源 IP');
    }

    /** CIDR 匹配(IPv4)。 */
    private function ipInCidr(string $ip, string $cidr): bool
    {
        [$subnet, $bits] = explode('/', $cidr);
        $bits = (int) $bits;
        $ipLong = ip2long($ip);
        $subnetLong = ip2long($subnet);
        if ($ipLong === false || $subnetLong === false || $bits <= 0) {
            return false;
        }
        $mask = -1 << (32 - $bits);

        return ($ipLong & $mask) === ($subnetLong & $mask);
    }

    /** 数据库主机必须解析为公网地址(安全审计 L-6,防安装向导 SSRF 盲探测内网 MySQL) */
    private function isPublicDbHost(string $host): bool
    {
        $check = trim($host, '[]');

        if (filter_var($check, FILTER_VALIDATE_IP)) {
            $ips = [$check];
        } else {
            $ips = [];
            foreach (@dns_get_record($check, DNS_A) ?: [] as $record) {
                if (is_string($record['ip'] ?? null)) {
                    $ips[] = $record['ip'];
                }
            }
            foreach (@gethostbynamel($check) ?: [] as $ip) {
                $ips[] = $ip;
            }
            $ips = array_values(array_unique($ips));
        }

        if ($ips === []) {
            // 无法解析的域名直接拒绝(安装场景 DB 主机必须可解析)。
            return false;
        }

        foreach ($ips as $ip) {
            // 允许本机回环(最常见的"MySQL 与站点同机"部署),其余必须是公网地址。
            if ($this->ipInCidr($ip, '127.0.0.0/8') || $ip === '::1') {
                continue;
            }
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                return false;
            }
        }

        return true;
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

        // 安全(V-5):环境指纹(php_version/扩展/目录可写性)也走安装来源防护,
        // 且不再向匿名调用方回显精确版本(布尔化,削减侦察面)
        $this->assertInstallAllowed(request());

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
        if ($this->isEffectivelyInstalled()) {
            return response()->json(['message' => '系统已安装'], 403);
        }
        $this->assertInstallAllowed($request);
        SecurityAudit::record($request, 'install.test_db', InstallController::class);

        $data = $request->validate([
            'host' => ['required', 'string', 'regex:/^[a-zA-Z0-9._\-.:]+$/'],
            'port' => 'required|integer|between:1,65535',
            'database' => ['required', 'string', 'regex:/^[a-zA-Z0-9_]+$/'],
            'username' => 'required|string|max:100',
            'password' => 'nullable|string',
        ]);

        // 安全(L-6):拒绝指向内网/回环地址的数据库地址,防安装向导被用作内网盲探测。
        if (! $this->isPublicDbHost((string) $data['host'])) {
            return response()->json([
                'success' => false,
                'message' => '数据库地址不允许为内网/回环地址',
            ], 422);
        }

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
                'message' => '数据库连接失败，请检查地址、端口和凭据',
            ], 422);
        }
    }

    /**
     * 执行安装
     * POST /api/install/run
     */
    public function run(Request $request): JsonResponse
    {
        if ($this->isEffectivelyInstalled()) {
            return response()->json(['message' => '系统已安装,如需重新安装请删除 storage/app/installed 文件'], 403);
        }
        $this->assertInstallAllowed($request);
        SecurityAudit::record($request, 'install.run', InstallController::class);

        $data = $request->validate([
            'db_host' => ['required', 'string', 'regex:/^[a-zA-Z0-9._\-.:]+$/'],
            'db_port' => 'required|integer|between:1,65535',
            'db_database' => ['required', 'string', 'regex:/^[a-zA-Z0-9_]+$/'],
            'db_username' => 'required|string|max:100',
            'db_password' => 'nullable|string',
            'admin_email' => 'required|email',
            'admin_password' => 'required|string|min:10|max:72',
        ]);

        // 安全(M-6):与 testDb 同口径,run() 同样拒绝内网 DB 地址(防借安装向导
        // 探测内网)。Docker/内网数据库部署在 .env 配置 ZCARD_INSTALL_ALLOW_PRIVATE_DB=true 豁免。
        if (! env('ZCARD_INSTALL_ALLOW_PRIVATE_DB', false) && ! $this->isPublicDbHost((string) $data['db_host'])) {
            return response()->json([
                'success' => false,
                'message' => '数据库地址不允许为内网/回环地址(内网/Docker 部署请在 .env 配置 ZCARD_INSTALL_ALLOW_PRIVATE_DB=true)',
            ], 422);
        }

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

            // 安全(M-6):安装即生产——.env.example 的 local+debug 默认值会被照抄进生产,
            // 异常页泄露堆栈/env/SQL。安装完成时强制收敛为生产配置。
            $this->writeEnv('APP_ENV', 'production');
            $this->writeEnv('APP_DEBUG', 'false');
            $this->writeEnv('LOG_LEVEL', 'error');
            // HTTPS 部署时收紧会话 Cookie(低危);HTTP 本地开发不受影响
            if ($request->isSecure()) {
                $this->writeEnv('SESSION_SECURE_COOKIE', 'true');
            }

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
            base_path('vendor'), // composer install 以 root 执行时 vendor 属主为 root,PHP 进程删除旧包会失败
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
