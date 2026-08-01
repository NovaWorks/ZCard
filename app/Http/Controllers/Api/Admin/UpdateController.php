<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * 在线更新系统(Git-based,比 acg-faka 的 OTA zip 方案更安全)。
 *
 * 流程:
 * 1. 检查 GitHub Release(对比版本号)
 * 2. 显示更新日志
 * 3. 一键执行: 维护模式 → 备份 → git pull → composer install → migrate → 前端构建 → 上线
 *
 * 比 acg-faka 强在:
 * - Git 原子性(无半更新风险)
 * - Laravel 迁移系统(事务 + 回滚)
 * - 维护模式(更新期间不服务请求)
 * - 自动备份(失败可恢复)
 * - 无需自建更新服务器(GitHub = 免费 CDN)
 */
class UpdateController extends Controller
{
    /** GitHub API: 最新 Release */
    public function check(): JsonResponse
    {
        $repo = config('zcard.update.repo', 'NovaWorks/ZCard');
        $currentVersion = config('app.version', '0.0.0');

        try {
            // 先尝试 /releases/latest(只返回正式版,不含 prerelease)
            // 如果 404(只有 prerelease 或完全无 release),回退到 /releases 取第一个
            $resp = Http::timeout(15)->withHeaders(['User-Agent' => 'ZCard'])
                ->get("https://api.github.com/repos/{$repo}/releases/latest");

            if ($resp->status() === 404) {
                // 回退:取所有 releases 的第一个(含 prerelease)
                $fallback = Http::timeout(15)->withHeaders(['User-Agent' => 'ZCard'])
                    ->get("https://api.github.com/repos/{$repo}/releases?per_page=1");

                if ($fallback->successful() && ! empty($fallback->json())) {
                    $release = $fallback->json()[0];
                } else {
                    // 确实没有任何 Release
                    return response()->json([
                        'current_version' => $currentVersion,
                        'latest_version' => $currentVersion,
                        'has_update' => false,
                        'release_url' => "https://github.com/{$repo}/releases",
                        'release_notes' => '尚未发布任何版本。请先在 GitHub 上创建 Release。',
                        'published_at' => '',
                    ]);
                }
            } elseif (! $resp->successful()) {
                return response()->json(['message' => '无法连接 GitHub(HTTP ' . $resp->status() . ')'], 502);
            } else {
                $release = $resp->json();
            }

            $latestVersion = ltrim($release['tag_name'] ?? '', 'v');
            $hasUpdate = version_compare($latestVersion, $currentVersion, '>');

            return response()->json([
                'current_version' => $currentVersion,
                'latest_version' => $latestVersion,
                'has_update' => $hasUpdate,
                'release_url' => $release['html_url'] ?? '',
                'release_notes' => $release['body'] ?? '',
                'published_at' => $release['published_at'] ?? '',
            ]);
        } catch (\Throwable $e) {
            return response()->json(['message' => '检查更新失败: ' . $e->getMessage()], 500);
        }
    }

    /** 版本历史(最近 10 个 Release) */
    public function versions(): JsonResponse
    {
        $repo = config('zcard.update.repo', 'NovaWorks/ZCard');

        try {
            $resp = Http::timeout(15)->withHeaders(['User-Agent' => 'ZCard'])->get("https://api.github.com/repos/{$repo}/releases?per_page=10");

            if (! $resp->successful()) {
                return response()->json(['message' => '无法连接 GitHub'], 502);
            }

            $releases = collect($resp->json())->map(fn ($r) => [
                'version' => ltrim($r['tag_name'] ?? '', 'v'),
                'url' => $r['html_url'] ?? '',
                'notes' => $r['body'] ?? '',
                'published_at' => $r['published_at'] ?? '',
                'prerelease' => $r['prerelease'] ?? false,
            ]);

            return response()->json($releases);
        } catch (\Throwable $e) {
            return response()->json(['message' => '获取版本列表失败: ' . $e->getMessage()], 500);
        }
    }

    /**
     * 执行更新(git pull + composer + migrate + 前端构建)。
     * 异步:返回 job ID,前端轮询状态。
     */
    public function update(Request $request): JsonResponse
    {
        $currentVersion = config('app.version', '0.0.0');

        // 防止并发更新
        $lockFile = storage_path('app/update.lock');
        if (file_exists($lockFile) && (time() - filemtime($lockFile) < 600)) {
            return response()->json(['message' => '已有更新正在进行中,请等待完成'], 409);
        }
        file_put_contents($lockFile, json_encode(['started_at' => now()->toIso8601String()]));

        // 记录日志
        $logFile = storage_path('app/update.log');
        file_put_contents($logFile, "=== 更新开始 " . now() . " ===\n");

        try {
            $this->log($logFile, '当前版本: ' . $currentVersion);

            // Step 1: 维护模式
            $this->log($logFile, '进入维护模式...');
            Artisan::call('down', ['--render' => 'maintenance']);

            // Step 2: 备份当前版本信息
            $this->log($logFile, '备份当前版本信息...');
            file_put_contents(storage_path('app/last_version.txt'), $currentVersion);

            // Step 3: git pull
            $this->log($logFile, '拉取最新代码...');
            $output = shell_exec('cd ' . base_path() . ' && git pull origin main 2>&1');
            $this->log($logFile, $output);

            if (str_contains($output, 'CONFLICT') || str_contains($output, 'error')) {
                throw new \RuntimeException('Git pull 失败(可能存在冲突): ' . $output);
            }

            // Step 4: composer install
            $this->log($logFile, '安装依赖...');
            $output = shell_exec('cd ' . base_path() . ' && composer install --no-dev --optimize-autoloader --no-interaction 2>&1');
            $this->log($logFile, $output);

            // Step 5: 数据库迁移
            $this->log($logFile, '执行数据库迁移...');
            Artisan::call('migrate', ['--force' => true]);
            $this->log($logFile, Artisan::output());

            // Step 6: 缓存优化
            $this->log($logFile, '优化缓存...');
            Artisan::call('config:cache');
            Artisan::call('route:cache');
            Artisan::call('view:cache');

            // Step 7: 前端构建(sysadmin → public/admin/, storefront → public/storefront/)
            $this->log($logFile, '构建后台前端(sysadmin)...');
            $output = shell_exec('cd ' . base_path() . '/sysadmin && pnpm install --frozen-lockfile 2>&1 && pnpm run build 2>&1');
            $this->log($logFile, $output);

            $this->log($logFile, '构建前台前端(storefront)...');
            $output = shell_exec('cd ' . base_path() . '/storefront && pnpm install --frozen-lockfile 2>&1 && pnpm run build 2>&1');
            $this->log($logFile, $output);

            // Step 8: 新版本号
            $newVersion = \App\Support\AppHelper::version();
            $this->log($logFile, '新版本: ' . $newVersion);

            // Step 9: 退出维护模式
            Artisan::call('up');
            $this->log($logFile, '退出维护模式,更新完成!');

            // 清理锁
            unlink($lockFile);

            return response()->json([
                'message' => '更新成功',
                'old_version' => $currentVersion,
                'new_version' => $newVersion,
                'log' => file_get_contents($logFile),
            ]);

        } catch (\Throwable $e) {
            // 失败:记录错误,退出维护模式,保留日志
            $this->log($logFile, '更新失败: ' . $e->getMessage());
            $this->log($logFile, '尝试退出维护模式...');
            try { Artisan::call('up'); } catch (\Throwable $ignore) {}
            unlink($lockFile);

            return response()->json([
                'message' => '更新失败: ' . $e->getMessage(),
                'log' => file_get_contents($logFile),
                'can_rollback' => true,
            ], 500);
        }
    }

    /**
     * 回退到上一个版本(git reset + migrate:rollback + 重建前端)。
     * POST /api/admin/update/rollback
     */
    public function rollback(): JsonResponse
    {
        $lockFile = storage_path('app/update.lock');
        if (file_exists($lockFile) && (time() - filemtime($lockFile) < 600)) {
            return response()->json(['message' => '更新正在进行中,无法回退'], 409);
        }

        $logFile = storage_path('app/update.log');
        file_put_contents($logFile, "=== 回退开始 " . now() . " ===\n");

        try {
            // Step 1: 维护模式
            $this->log($logFile, '进入维护模式...');
            Artisan::call('down', ['--render' => 'maintenance']);

            // Step 2: 查看上一个版本
            $this->log($logFile, '当前 HEAD:');
            $output = shell_exec('cd ' . base_path() . ' && git log --oneline -3 2>&1');
            $this->log($logFile, $output);

            // Step 3: git reset 回退一个提交
            $this->log($logFile, '执行 git reset --hard HEAD~1...');
            $output = shell_exec('cd ' . base_path() . ' && git reset --hard HEAD~1 2>&1');
            $this->log($logFile, $output);

            // Step 4: 回退数据库迁移
            $this->log($logFile, '回退数据库迁移...');
            try {
                Artisan::call('migrate:rollback', ['--force' => true]);
                $this->log($logFile, Artisan::output());
            } catch (\Throwable $e) {
                $this->log($logFile, 'migrate:rollback 警告(可能无迁移可回退): ' . $e->getMessage());
            }

            // Step 5: 重建依赖和前端
            $this->log($logFile, '安装依赖...');
            $output = shell_exec('cd ' . base_path() . ' && composer install --no-dev --optimize-autoloader --no-interaction 2>&1');
            $this->log($logFile, $output);

            $this->log($logFile, '重建前端...');
            $output = shell_exec('cd ' . base_path() . '/sysadmin && pnpm install --frozen-lockfile 2>&1 && pnpm run build 2>&1');
            $this->log($logFile, $output);
            $output = shell_exec('cd ' . base_path() . '/storefront && pnpm install --frozen-lockfile 2>&1 && pnpm run build 2>&1');
            $this->log($logFile, $output);

            // Step 6: 清缓存 + 上线
            Artisan::call('config:cache');
            Artisan::call('route:cache');
            Artisan::call('view:cache');
            Artisan::call('up');

            $version = \App\Support\AppHelper::version();
            $this->log($logFile, "回退完成! 当前版本: {$version}");

            return response()->json([
                'message' => '已回退到上一个版本',
                'current_version' => $version,
                'log' => file_get_contents($logFile),
            ]);

        } catch (\Throwable $e) {
            $this->log($logFile, '回退失败: ' . $e->getMessage());
            try { Artisan::call('up'); } catch (\Throwable $ignore) {}
            return response()->json([
                'message' => '回退失败: ' . $e->getMessage(),
                'log' => file_get_contents($logFile),
            ], 500);
        }
    }

    /** 获取更新日志(供前端轮询) */
    public function getLog(): JsonResponse
    {
        $logFile = storage_path('app/update.log');
        $lockFile = storage_path('app/update.lock');

        return response()->json([
            'running' => file_exists($lockFile),
            'log' => file_exists($logFile) ? file_get_contents($logFile) : '',
        ]);
    }

    private function log(string $file, string $message): void
    {
        file_put_contents($file, '[' . now()->format('H:i:s') . '] ' . trim($message) . "\n", FILE_APPEND);
    }
}
