<?php

namespace App\Console\Commands;

use App\Models\Merchant;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;

class InstallCommand extends Command
{
    protected $signature = 'zcard:install {--email=admin@zcard.local : 超级管理员邮箱}';

    protected $description = 'ZCard 系统初始化：迁移、角色权限、默认商户、超管账号（随机8位密码）';

    public function handle(): int
    {
        $this->info('ZCard 系统初始化');

        // 1. APP_KEY（若为空才生成；避免覆盖已有 key）
        if (empty(config('app.key'))) {
            $this->call('key:generate');
            $this->info(' ✔ 生成应用密钥');
        }

        // 2. 卡密加密密钥（与 APP_KEY 同约定：base64: 前缀）
        if (empty(config('zcard.card_encryption_key'))) {
            $key = 'base64:'.base64_encode(random_bytes(32));
            $this->writeEnv('CARD_ENCRYPTION_KEY', $key);
            $this->info(' ✔ 生成卡密加密密钥');
        }

        // 3. 迁移
        $this->call('migrate', ['--force' => true]);
        $this->info(' ✔ 迁移数据库');

        // 4. 角色与权限（幂等）
        foreach (['super_admin', 'merchant', 'user'] as $roleName) {
            Role::firstOrCreate(['name' => $roleName]);
        }
        // filament-shield：初始化 Shield 核心（创建 super_admin 角色、配置 Gate）。--silent 避免交互。
        $this->callSilently('shield:setup', ['--silent' => true]);
        $this->info(' ✔ 创建角色与权限');

        // 5. 超管账号（幂等）— 必须先于商户创建，因为 merchants.user_id 外键引用 users.id
        $email = $this->option('email');
        $existingUser = User::where('email', $email)->first();
        $newPassword = null;

        if ($existingUser) {
            $this->warn("   邮箱 {$email} 已存在账号，跳过创建。");
            $adminUser = $existingUser;
        } else {
            $newPassword = Str::random(8); // 8 位随机密码（spec §7.2）
            $adminUser = User::create([
                'username' => 'admin',
                'name' => 'Super Admin',
                'email' => $email,
                'password' => $newPassword,
                'status' => 1,
                'password_changed_at' => null, // 强制首次改密
            ]);
            $adminUser->assignRole('super_admin');
            // Shield 的 super_admin 绕过仅校验角色名（FilamentShieldServiceProvider::57-58），
            // assignRole('super_admin') 已足够，无需再跑 shield:super-admin（其会交互式提问）。
        }

        // 6. 默认商户（merchant_id=1，slug=default）— 店主为超管
        Merchant::firstOrCreate(
            ['slug' => 'default'],
            [
                'user_id' => $adminUser->id,
                'name' => '默认商户',
                'status' => 1,
                'commission_rate' => 0,
            ]
        );
        $this->info(' ✔ 创建默认商户（slug=default）');

        if ($newPassword !== null) {
            $this->info(' ✔ 创建超级管理员账号');
            $this->line('');
            $this->line("   邮箱：  {$email}");
            $this->line('   初始密码（随机生成，请妥善保存）：');
            $this->line('   ┌──────────────────────────┐');
            $this->line("   │  {$newPassword}              │");
            $this->line('   └──────────────────────────┘');
            $this->warn('   ⚠ 首次登录后请立即在「个人设置」修改密码');
        }

        $this->info('');
        $this->info(' ✔ 安装完成。访问 /admin 登录。');

        return self::SUCCESS;
    }

    /** 写入 .env（键已存在则更新，否则追加） */
    private function writeEnv(string $key, string $value): void
    {
        $path = base_path('.env');
        if (! file_exists($path)) {
            return;
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
