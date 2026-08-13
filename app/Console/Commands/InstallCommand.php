<?php

namespace App\Console\Commands;

use App\Models\Merchant;
use App\Models\User;
use App\Support\AppHelper;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;

class InstallCommand extends Command
{
    protected $signature = 'zcard:install
                            {--email=admin@zcard.local : 超级管理员邮箱}
                            {--password= : 超级管理员密码(不传则随机生成)}
                            {--skip-db : 跳过数据库配置交互(使用现有 .env)}';

    protected $description = 'ZCard 系统初始化：配置数据库、迁移、角色权限、默认商户、超管账号';

    public function handle(): int
    {
        $this->info('');
        $this->info('╔══════════════════════════════════════════╗');
        $this->info('║     ZCard 安装向导                        ║');
        $this->info('║     现代化虚拟商品自动发卡系统             ║');
        $this->info('╚══════════════════════════════════════════╝');
        $this->info('');

        // ─── Step 1: 环境检查(先检查再改 .env,避免检查失败时 .env 已被修改) ───
        $this->info('');
        $this->info('📋 环境检查');
        if (! $this->checkEnvironment()) {
            return self::FAILURE;
        }

        // ─── Step 1.5: 修复关键目录权限 ───
        // composer install / git pull 用 root 执行时,目录属主为 root,而 PHP-FPM 以
        // www 用户运行 → 不可写 → 500。这里尝试递归 chmod 修复(同组可写)。
        $this->fixPermissions();

        // ─── Step 2: 数据库配置(交互式) ───
        if (! $this->option('skip-db')) {
            $this->configureDatabase();
        }

        // ─── Step 3: APP_KEY ───
        if (empty(config('app.key'))) {
            $this->call('key:generate');
            $this->info(' ✔ 生成应用密钥');
        }

        // ─── Step 4: 卡密加密密钥 ───
        if (empty(config('zcard.card_encryption_key'))) {
            $key = 'base64:'.base64_encode(random_bytes(32));
            $this->writeEnv('CARD_ENCRYPTION_KEY', $key);
            $this->info(' ✔ 生成卡密加密密钥');
        }

        // ─── Step 5: 测试数据库连接 ───
        $this->info('');
        $this->info('🔗 测试数据库连接...');
        try {
            \DB::connection()->getPdo();
            $this->info(' ✔ 数据库连接成功');
        } catch (\Throwable $e) {
            $this->error(' ✘ 数据库连接失败: '.$e->getMessage());
            $this->warn('   请检查 .env 中的 DB_HOST / DB_DATABASE / DB_USERNAME / DB_PASSWORD');

            return self::FAILURE;
        }

        // ─── Step 6: 迁移 ───
        $this->info('');
        $this->call('migrate', ['--force' => true]);
        $this->info(' ✔ 数据库迁移完成');

        // ─── Step 7: 角色与权限 ───
        foreach (['super_admin', 'merchant', 'user'] as $roleName) {
            Role::firstOrCreate(['name' => $roleName]);
        }
        $this->callSilently('shield:setup', ['--silent' => true]);
        $this->info(' ✔ 创建角色与权限');

        // ─── Step 7.5: storage 公开链接 ───
        // 素材管理/上传的图片存储在 storage/app/public,需 public/storage 软链接才能经
        // /storage/... 访问。缺失时上传的图片会 403(被 Laravel 路由接管返回"访问被拒绝")。
        $this->callSilently('storage:link');
        $this->info(' ✔ 创建 storage 软链接(public/storage)');

        // ─── Step 8: 管理员账号 ───
        $email = $this->option('email');
        $existingUser = User::where('email', $email)->first();
        if (! $existingUser) {
            // 也检查 username=admin 是否已存在(避免唯一约束冲突)
            $existingUser = User::where('username', 'admin')->first();
        }
        $newPassword = null;

        if ($existingUser) {
            $this->warn("   管理员账号已存在({$existingUser->email}),跳过创建");
            $adminUser = $existingUser;
            // 确保 super_admin 角色
            if (! $adminUser->hasRole('super_admin')) {
                $adminUser->assignRole('super_admin');
            }
        } else {
            $newPassword = $this->option('password') ?: Str::random(8);
            $adminUser = User::create([
                'username' => 'admin',
                'name' => 'Super Admin',
                'email' => $email,
                'password' => $newPassword,
                'status' => 1,
                'password_changed_at' => null,
            ]);
            $adminUser->assignRole('super_admin');
        }

        // ─── Step 9: 默认商户 ───
        Merchant::firstOrCreate(
            ['slug' => 'default'],
            [
                'user_id' => $adminUser->id,
                'name' => '默认商户',
                'status' => 1,
                'commission_rate' => 0,
            ]
        );
        $this->info(' ✔ 创建默认商户');

        // ─── 安装锁 ───
        file_put_contents(storage_path('app/installed'), json_encode([
            'version' => AppHelper::version(),
            'installed_at' => now()->toIso8601String(),
        ]));

        // ─── 完成 ───
        $this->info('');
        $this->info('═══════════════════════════════════════════');
        $this->info('  🎉 ZCard 安装完成!');
        $this->info('═══════════════════════════════════════════');

        if ($newPassword !== null) {
            $this->info('');
            $this->info('  管理员账号:');
            $this->line("    邮箱:  {$email}");
            $this->line("    密码:  {$newPassword}");
            $this->warn('    ⚠ 首次登录后请立即修改密码');
        }

        $this->info('');
        $this->info('  后台地址: '.config('app.url').'/admin');
        $this->info('  前台地址: '.config('app.url'));
        $this->info('');

        return self::SUCCESS;
    }

    /**
     * 交互式数据库配置
     */
    private function configureDatabase(): void
    {
        $this->info('');
        $this->info('📋 数据库配置(直接回车使用默认值)');

        $currentHost = config('database.connections.mysql.host', '127.0.0.1');
        $currentPort = config('database.connections.mysql.port', '3306');
        $currentDb = config('database.connections.mysql.database', 'zcard');
        $currentUser = config('database.connections.mysql.username', 'root');
        $currentPass = config('database.connections.mysql.password', '');

        $host = $this->ask('数据库主机', $currentHost);
        $port = $this->ask('数据库端口', $currentPort);
        $database = $this->ask('数据库名', $currentDb);
        $username = $this->ask('数据库用户名', $currentUser);
        $password = $this->secret('数据库密码(输入时不显示)');

        // 写入 .env
        $this->writeEnv('DB_HOST', $host);
        $this->writeEnv('DB_PORT', $port);
        $this->writeEnv('DB_DATABASE', $database);
        $this->writeEnv('DB_USERNAME', $username);
        if ($password !== null && $password !== '') {
            $this->writeEnv('DB_PASSWORD', $password);
        }

        $this->info(' ✔ 数据库配置已写入 .env');

        // 刷新进程内 DB 配置(让新 .env 的凭据立即生效)
        config([
            'database.connections.mysql.host' => $host,
            'database.connections.mysql.port' => $port,
            'database.connections.mysql.database' => $database,
            'database.connections.mysql.username' => $username,
            'database.connections.mysql.password' => $password ?? '',
        ]);
        \DB::purge('mysql');
        \DB::reconnect('mysql');
    }

    /**
     * 环境检查(返回 bool,不硬 exit)
     */
    private function checkEnvironment(): bool
    {
        $checks = [
            'PHP >= 8.3' => version_compare(PHP_VERSION, '8.3.0', '>='),
            'PDO MySQL' => extension_loaded('pdo_mysql'),
            'mbstring' => extension_loaded('mbstring'),
            'OpenSSL' => extension_loaded('openssl'),
            'bcmath' => extension_loaded('bcmath'),
            'JSON' => extension_loaded('json'),
            'cURL' => extension_loaded('curl'),
            'GD/Imagick' => extension_loaded('gd') || extension_loaded('imagick'),
            'Redis(可选)' => extension_loaded('redis'),
        ];

        $allPass = true;
        foreach ($checks as $name => $passed) {
            $ext = str_contains($name, '可选') ? '⚠' : '✘';
            $mark = $passed ? '✔' : $ext;
            $this->line("   {$mark} {$name}".($passed ? '' : (str_contains($name, '可选') ? ' (跳过)' : ' (必需!)')));
            if (! $passed && ! str_contains($name, '可选')) {
                $allPass = false;
            }
        }

        if (! $allPass) {
            $this->error('');
            $this->error('存在未满足的必需环境要求,请安装对应的 PHP 扩展后重试');

            return false;
        }

        return true;
    }

    /**
     * 修复关键目录权限。
     * composer install / git pull 用 root 执行时,目录属主为 root,而 PHP-FPM 以
     * www 用户运行 → 不可写 → 500。CLI 以 root 运行时还能顺带 chown 修正属主。
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

        // CLI 以 root 运行时,顺带把属主修正为 PHP-FPM 用户(宝塔默认 www)
        // posix_getuid 需要 posix 扩展;无扩展时静默跳过
        if (function_exists('posix_getuid') && posix_getuid() === 0) {
            $phpUser = $this->detectPhpFpmUser();
            if ($phpUser) {
                foreach ($dirs as $dir) {
                    @chown($dir, $phpUser);
                    @chgrp($dir, $phpUser);
                }
                $this->info(" ✔ 目录权限已修复(chown {$phpUser}:{$phpUser} + chmod 775)");
            } else {
                $this->info(' ✔ 目录权限已修复(chmod 775;属主请手动 chown)');
            }
        }
    }

    /**
     * 探测 PHP-FPM 运行用户(宝塔/lnmp 默认 www,aapanel 默认 www-data)。
     */
    private function detectPhpFpmUser(): ?string
    {
        // 常见 PHP-FPM 用户,优先级从高到低
        foreach (['www', 'www-data', 'nginx', 'apache'] as $user) {
            if (function_exists('posix_getpwnam') && @posix_getpwnam($user)) {
                return $user;
            }
        }

        return null;
    }

    /** 写入 .env(带引号保护含特殊字符的值) */
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
