<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;

/**
 * 应用辅助:版本号等。
 *
 * 版本号从项目根目录的 VERSION 文件读取(每次发版写死,随 git 提交)。
 * 这是唯一可靠的方案:不依赖 git 命令(proc_open)、不依赖 .git 目录结构,
 * 宝塔禁用函数、容器无 git、非 git clone 部署都能正确显示版本号。
 */
class AppHelper
{
    /**
     * 当前应用版本。从 VERSION 文件读取,缓存 60 秒。
     * 缓存后端不可用时直接读文件(不抛异常,版本号是基础信息)。
     */
    public static function version(): string
    {
        try {
            return Cache::remember('app:version', 60, function () {
                return self::resolveVersion();
            });
        } catch (\Throwable) {
            // 缓存后端不可用(如测试环境无 DB)→ 直接读文件
            return self::resolveVersion();
        }
    }

    private static function resolveVersion(): string
    {
        $versionFile = base_path('VERSION');
        if (is_file($versionFile)) {
            $version = trim((string) file_get_contents($versionFile));
            if ($version !== '') {
                return $version;
            }
        }

        // VERSION 文件不存在(理论上不会发生)→ 回退 config
        return config('app.version', '1.0.0');
    }

    /** 清除版本缓存(更新/回滚后调用,确保下次读到新版本) */
    public static function clearVersionCache(): void
    {
        try {
            Cache::forget('app:version');
        } catch (\Throwable) {
            // 缓存后端不可用 → 无需清理
        }
    }

    /**
     * GitHub 仓库名(owner/repo)。
     */
    public static function repo(): string
    {
        return config('zcard.update.repo', 'NovaWorks/ZCard');
    }
}
