<?php

namespace App\Support;

/**
 * 应用辅助:版本号等。
 * 版本号从 config/app.php 读取,git pull 后自动更新。
 */
class AppHelper
{
    /**
     * 当前应用版本(从 config 读取)。
     * 部署时通过 GitHub tag 或 config/app.php 的 version 字段管理。
     */
    public static function version(): string
    {
        return config('app.version', '1.0.0');
    }

    /**
     * GitHub 仓库名(owner/repo)。
     */
    public static function repo(): string
    {
        return config('zcard.update.repo', 'NovaWorks/ZCard');
    }
}
