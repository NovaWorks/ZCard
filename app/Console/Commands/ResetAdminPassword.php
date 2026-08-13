<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * 安全应急命令(安全审计 C-1):
 * 一键重置超级管理员密码。适用于:
 * - 存量站点曾以默认凭据(admin/admin123456)存在风险;
 * - 管理员忘记密码的应急恢复。
 *
 * 用法:
 *   php artisan zcard:reset-admin-password
 *   php artisan zcard:reset-admin-password --password='YourNewPassword'
 */
class ResetAdminPassword extends Command
{
    protected $signature = 'zcard:reset-admin-password
                            {--username=admin : 要重置的管理员用户名}
                            {--password= : 新密码(不传则随机生成)}';

    protected $description = '重置超级管理员密码(安全应急/忘记密码恢复),新密码仅在本终端输出一次';

    public function handle(): int
    {
        $username = $this->option('username');
        $user = User::withTrashed()->where('username', $username)->first();

        if (! $user) {
            $this->error("用户不存在: {$username}");

            return self::FAILURE;
        }

        $password = $this->option('password') ?: Str::random(16);
        if (strlen($password) < 8) {
            $this->error('密码长度至少 8 位');

            return self::FAILURE;
        }

        $user->forceFill([
            'password' => $password,
            'status' => 1,
            'deleted_at' => null,
            'password_changed_at' => null,
        ])->save();
        $user->tokens()->delete();
        $user->assignRole('super_admin');

        $this->info('════════════════════════════════════════════');
        $this->info("  管理员账号: {$user->username}");
        $this->info("  新密码: {$password}");
        $this->warn('  请立即登录后台并修改密码!此密码仅显示这一次。');
        $this->info('════════════════════════════════════════════');

        return self::SUCCESS;
    }
}
