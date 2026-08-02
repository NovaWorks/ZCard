<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Symfony\Component\Process\Process;

/**
 * 应用辅助:版本号等。
 *
 * 版本号优先从 git tag 读取(git describe --tags),这样 git pull / 拉取新 release
 * tag 后本地版本号自动跟上 GitHub Release,无需手动改 config。
 * 无 git 或无 tag 时回退 config/app.php 的 version(默认 1.0.0)。
 * 结果缓存 5 分钟,避免每次请求都起子进程。
 */
class AppHelper
{
    /**
     * 当前应用版本。
     * 优先级:git describe --tags → config/app.php version → 1.0.0。
     * 缓存 5 分钟;缓存后端不可用时直接计算(不抛异常,版本号是基础信息)。
     */
    public static function version(): string
    {
        try {
            return Cache::remember('app:version', 300, function () {
                return self::resolveVersion();
            });
        } catch (\Throwable) {
            // 缓存后端不可用(如测试环境无 DB)→ 直接计算
            return self::resolveVersion();
        }
    }

    private static function resolveVersion(): string
    {
        $gitVersion = self::versionFromGit();
        if ($gitVersion !== null) {
            return $gitVersion;
        }

        return config('app.version', '1.0.0');
    }

    /**
     * 从 git 读取版本号。优先用 git describe(含 tag 后提交计数),失败时
     * 直接读 .git 目录下的 tag 文件(纯文件操作,不受 disable_functions 限制,
     * 宝塔/容器环境 proc_open 被禁用时 git describe 不可用,这是兜底)。
     *
     * - 命中 tag 返回纯净版本(如 v1.0.1 → 1.0.1)
     * - tag 之后有提交返回带后缀(如 1.0.1-28-g00ba1fb),取首段版本号
     * - 无 git/无 tag 返回 null(回退 config)
     */
    private static function versionFromGit(): ?string
    {
        // 方案 1:git describe(最准,含提交计数后缀;需 proc_open)
        try {
            $process = Process::fromShellCommandline(
                'git describe --tags --always 2>/dev/null',
                base_path(),
            );
            $process->run();
            $output = trim($process->getOutput());

            if ($process->isSuccessful() && $output !== '' && ! str_starts_with($output, 'v0.0.0')) {
                $first = explode('-', $output)[0];

                return ltrim($first, 'vV');
            }
        } catch (\Throwable) {
            // proc_open 被禁用 / 无 git → 尝试方案 2
        }

        // 方案 2:纯文件读取 .git/refs/tags/ 下最新的 tag(无需执行命令)
        return self::versionFromGitFiles();
    }

    /**
     * 直接读 .git 目录获取最新 tag 版本号(纯文件操作,不执行任何命令)。
     * 取版本号最大的 tag(v1.1.5 > v1.1.4 > v1.0.0),而非时间最近的。
     */
    private static function versionFromGitFiles(): ?string
    {
        $tagsDir = base_path('.git/refs/tags');
        if (! is_dir($tagsDir)) {
            return null;
        }

        $tags = array_diff(scandir($tagsDir), ['.', '..']);
        if (empty($tags)) {
            return null;
        }

        // 按语义版本排序,取最大的
        usort($tags, function ($a, $b) {
            return version_compare(ltrim($b, 'vV'), ltrim($a, 'vV'));
        });

        $latest = $tags[0];

        return ltrim($latest, 'vV');
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
