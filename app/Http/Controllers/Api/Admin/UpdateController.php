<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Support\AppHelper;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;

/**
 * 在线更新系统(Git-based,比 acg-faka 的 OTA zip 方案更安全)。
 *
 * 流程:
 * 1. 检查 GitHub Release(对比版本号)
 * 2. 显示更新日志
 * 3. 一键执行: 维护模式 → 备份 → git pull → composer install → migrate → 前端构建 → 上线
 *
 * 注意: 容器/Docker 环境下 git SSH 可能不可用,自动降级 HTTPS(公共仓库)。
 */
class UpdateController extends Controller
{
    /** GitHub API: 最新 Release */
    public function check(): JsonResponse
    {
        $repo = config('zcard.update.repo', 'NovaWorks/ZCard');
        // 顶栏版本徽章依赖此接口,必须返回最新版本号:先清缓存再读,
        // 避免更新后 60 秒内仍显示旧版本(用户感知"更新了但版本号没变")。
        AppHelper::clearVersionCache();
        $currentVersion = AppHelper::version();

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
                return response()->json(['message' => '无法连接 GitHub(HTTP '.$resp->status().')'], 502);
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
            return response()->json(['message' => '检查更新失败: '.$e->getMessage()], 500);
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
            return response()->json(['message' => '获取版本历史失败'], 500);
        }
    }

    /**
     * 执行更新(git pull + composer + migrate + 前端构建)。
     * 异步:返回 job ID,前端轮询状态。
     */
    public function update(Request $request): JsonResponse
    {
        $currentVersion = AppHelper::version();
        $userFilesPreserved = false;

        // 原子锁防止并发更新/回退；文件仅供前端展示运行状态。
        $operationLock = Cache::lock('zcard:system-update', 600);
        if (! $operationLock->get()) {
            return response()->json(['message' => '已有更新或回退正在进行中,请等待完成'], 409);
        }

        $lockFile = storage_path('app/update.lock');
        file_put_contents($lockFile, json_encode(['started_at' => now()->toIso8601String()]));

        // 记录日志
        $logFile = storage_path('app/update.log');
        file_put_contents($logFile, '=== 更新开始 '.now()." ===\n");

        try {
            $this->log($logFile, '当前版本: '.$currentVersion);

            // Step 1: 维护模式(不指定 render 视图,避免找不到组件)
            $this->log($logFile, '进入维护模式...');
            Artisan::call('down');

            // Step 2: 备份当前版本信息
            $this->log($logFile, '备份当前版本信息...');
            file_put_contents(storage_path('app/last_version.txt'), $currentVersion);

            // Step 3: 同步远程代码(fetch + reset,比 pull 更不易冲突)
            $this->log($logFile, '拉取最新代码...');
            $this->ensureGitSafeDirectory();
            $this->ensureHttpsRemote();
            $this->preserveUserFiles();
            $userFilesPreserved = true;
            // 显式更新远程跟踪引用,并以本次 fetch 的 FETCH_HEAD 为准。
            // 部分客户仓库缺少 remote.origin.fetch,仅 fetch origin main 不会更新 origin/main。
            $output = $this->shell($this->gitSyncCommand(), true, 'Git 同步失败');
            $this->log($logFile, $output);

            // 检测致命错误(网络问题、文件权限等),必须在 restoreUserFiles 之前:
            // 否则 .env 恢复时的权限异常会掩盖真正的 git 失败原因。
            if (str_contains($output, 'insufficient permission') && str_contains($output, '.git/objects')) {
                throw new \RuntimeException(
                    'Git 目录权限不足: PHP 进程无权写入 .git/objects(宝塔环境 .git 属主可能不是 www)。'
                    .'请在服务器 SSH 执行(需 root): chown -R www:www '.base_path()
                    .' 然后重新点击在线更新。'
                );
            }
            if (str_contains($output, 'fatal:') || str_contains($output, 'Could not resolve host') || str_contains($output, 'Permission denied')) {
                throw new \RuntimeException('Git pull 失败: '.$output);
            }
            $this->restoreUserFiles();
            $userFilesPreserved = false;
            $this->assertGitReferenceMatches('FETCH_HEAD');

            // Step 4: composer install(设置 COMPOSER_HOME 避免容器无 HOME)
            $this->log($logFile, '安装依赖...');
            $this->assertVendorWritable();
            $output = $this->composerInstall();
            $this->log($logFile, $output);

            // Step 5: 数据库迁移
            $this->log($logFile, '执行数据库迁移...');
            Artisan::call('migrate', ['--force' => true]);
            $this->log($logFile, Artisan::output());

            // Step 6: 缓存优化(先清后建,避免旧缓存)
            // 注意: 不执行 view:cache —— Filament v5 有动态 Blade 组件(如 modal),
            // view:cache 预编译时会找不到而崩溃。config/route cache 是安全的。
            // 重要: 先物理删除 bootstrap/cache 里的旧缓存文件(php 文件),
            // 避免 config:clear 自身因旧缓存崩溃(config:clear 也需要读 config)。
            $this->log($logFile, '优化缓存...');
            $this->clearBootstrapCache();
            Artisan::call('package:discover');
            Artisan::call('config:cache');
            // routes/api.php 存在闭包路由(如 payments/return),Laravel 的 route:cache
            // 无法序列化闭包会抛异常。此时保持"无路由缓存"运行(路由实时编译,新路由立即生效),
            // 但不能让整个更新流程误报失败,故单独捕获降级。
            try {
                Artisan::call('route:cache');
            } catch (\Throwable $e) {
                $this->log($logFile, 'route:cache 跳过(存在闭包路由,路由实时加载): '.$e->getMessage());
            }

            // Step 7: 前端构建(有 pnpm 用 pnpm,否则跳过——编译产物已在仓库)
            $this->buildFrontend($logFile, 'sysadmin');
            $this->buildFrontend($logFile, 'storefront');

            // Step 7.5: 校验前端产物完整性(防止 index.html 和 assets 不同步导致 404 白屏)
            $this->verifyFrontendAssets($logFile, 'admin');
            $this->verifyFrontendAssets($logFile, 'storefront');

            // Step 8: 新版本号(清缓存确保读到 git pull 后的新 tag)
            AppHelper::clearVersionCache();
            $newVersion = AppHelper::version();
            $this->log($logFile, '新版本: '.$newVersion);

            // Step 9: 退出维护模式
            Artisan::call('up');
            $this->log($logFile, '退出维护模式,更新完成!');

            return response()->json([
                'message' => '更新成功',
                'old_version' => $currentVersion,
                'new_version' => $newVersion,
                'log' => file_get_contents($logFile),
            ]);

        } catch (\Throwable $e) {
            if ($userFilesPreserved) {
                $this->restoreUserFiles();
            }
            // 失败:记录错误,退出维护模式,清缓存,保留日志
            $this->log($logFile, '更新失败: '.$e->getMessage());
            $this->log($logFile, '清理缓存 + 退出维护模式...');
            $this->clearBootstrapCache();
            try {
                Artisan::call('package:discover');
            } catch (\Throwable $ignore) {
            }
            try {
                Artisan::call('up');
            } catch (\Throwable $ignore) {
            }

            return response()->json([
                'message' => '更新失败: '.$e->getMessage(),
                'log' => file_get_contents($logFile),
                'can_rollback' => true,
            ], 500);
        } finally {
            @unlink($lockFile);
            $operationLock->release();
        }
    }

    /**
     * 回退到上一个版本(git reset + migrate:rollback + 重建前端)。
     * POST /api/admin/update/rollback
     */
    public function rollback(): JsonResponse
    {
        $userFilesPreserved = false;
        $operationLock = Cache::lock('zcard:system-update', 600);
        if (! $operationLock->get()) {
            return response()->json(['message' => '已有更新或回退正在进行中,无法回退'], 409);
        }

        $lockFile = storage_path('app/update.lock');
        file_put_contents($lockFile, json_encode(['started_at' => now()->toIso8601String(), 'operation' => 'rollback']));

        $logFile = storage_path('app/update.log');
        file_put_contents($logFile, '=== 回退开始 '.now()." ===\n");

        try {
            // Step 1: 维护模式(不指定 render 视图)
            $this->log($logFile, '进入维护模式...');
            Artisan::call('down');

            // Step 2: git reset 回退(保护 .env 不被覆盖)
            $this->log($logFile, '回退代码到上一版本...');
            $this->ensureGitSafeDirectory();
            $this->ensureHttpsRemote();
            $this->preserveUserFiles();
            $userFilesPreserved = true;
            $output = $this->shell(
                'cd '.base_path().' && '.$this->gitCmd('reset --hard HEAD~1').' 2>&1',
                true,
                'Git 回退失败'
            );
            $this->log($logFile, $output);

            // reset 失败要在 restoreUserFiles 之前检测,避免恢复 .env 时的权限异常掩盖真正的失败原因
            if (str_contains($output, 'fatal:') || str_contains($output, 'Permission denied')) {
                throw new \RuntimeException('Git 回退失败: '.$output);
            }
            $this->restoreUserFiles();
            $userFilesPreserved = false;

            // Step 3: 安装依赖(可能需要降级)
            $this->log($logFile, '安装依赖...');
            $this->assertVendorWritable();
            $output = $this->composerInstall();
            $this->log($logFile, $output);

            // Step 4: 数据库回滚迁移
            $this->log($logFile, '回滚数据库迁移...');
            Artisan::call('migrate:rollback', ['--force' => true]);
            $this->log($logFile, Artisan::output());

            // Step 5: 前端构建
            $this->buildFrontend($logFile, 'sysadmin');
            $this->buildFrontend($logFile, 'storefront');

            // Step 5.5: 校验前端产物完整性
            $this->verifyFrontendAssets($logFile, 'admin');
            $this->verifyFrontendAssets($logFile, 'storefront');

            // Step 6: 清缓存 + 上线
            Artisan::call('config:clear');
            Artisan::call('route:clear');
            Artisan::call('view:clear');
            Artisan::call('config:cache');
            // 闭包路由无法被 route:cache 序列化,失败即保持实时加载(路由已 route:clear)
            try {
                Artisan::call('route:cache');
            } catch (\Throwable $e) {
                $this->log($logFile, 'route:cache 跳过(存在闭包路由,路由实时加载): '.$e->getMessage());
            }
            Artisan::call('up');

            // 清版本缓存确保读到 git reset 后的 tag
            AppHelper::clearVersionCache();
            $version = AppHelper::version();
            $this->log($logFile, "回退完成! 当前版本: {$version}");

            return response()->json([
                'message' => '已回退到上一个版本',
                'current_version' => $version,
                'log' => file_get_contents($logFile),
            ]);

        } catch (\Throwable $e) {
            if ($userFilesPreserved) {
                $this->restoreUserFiles();
            }
            $this->log($logFile, '回退失败: '.$e->getMessage());
            $this->clearBootstrapCache();
            try {
                Artisan::call('package:discover');
            } catch (\Throwable $ignore) {
            }
            try {
                Artisan::call('up');
            } catch (\Throwable $ignore) {
            }

            return response()->json([
                'message' => '回退失败: '.$e->getMessage(),
                'log' => file_get_contents($logFile),
            ], 500);
        } finally {
            @unlink($lockFile);
            $operationLock->release();
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

    // ─── 私有辅助 ───

    private function log(string $file, string $message): void
    {
        $message = trim($message);
        if ($message !== '') {
            file_put_contents($file, '['.now()->format('H:i:s').'] '.$message."\n", FILE_APPEND);
        }
    }

    /**
     * 执行 shell 命令,设好环境变量避免容器问题。
     *
     * 优先用 Symfony Process(更可靠的错误处理);若 proc_open 被 disable_functions
     * 禁用(宝塔常见),抛出含清晰提示的异常,而非 PHP fatal error。
     */
    private function shell(string $command, bool $mustSucceed = false, string $failureMessage = '命令执行失败'): string
    {
        if (! $this->canExec()) {
            throw new \RuntimeException(
                '服务器禁用了执行命令所需的函数(proc_open / shell_exec)。'
                .'请在宝塔面板「PHP 设置 → 禁用函数」中移除 proc_open 和 shell_exec 后重试。'
            );
        }

        $process = Process::fromShellCommandline($command, base_path());
        $process->setTimeout(600);
        $process->run();

        $output = $process->getOutput().$process->getErrorOutput();
        if ($mustSucceed && ! $process->isSuccessful()) {
            throw new \RuntimeException($failureMessage.'(退出码 '.$process->getExitCode().'): '.$output);
        }

        return $output;
    }

    /**
     * composer install 预检:vendor 目录必须可写。
     *
     * composer 在依赖增删时会**删除/替换旧包文件**;若服务器曾用 root 执行过
     * composer install / git pull,vendor 内文件属主为 root,而 PHP-FPM 以 www
     * 用户运行 → 删除失败 → composer 中断回滚。此预检在 composer 执行前给出
     * 明确修复指令,而不是等 composer 报笼统错误。
     */
    private function assertVendorWritable(): void
    {
        $vendor = base_path('vendor');
        if (! is_dir($vendor) || is_writable($vendor)) {
            return;
        }

        throw new \RuntimeException(
            'vendor 目录不可写:可能曾用 root 执行过 composer install,文件属主为 root,'
            .'而 PHP 进程(www)无权删除/替换旧包文件,导致 composer 更新中断。'
            .'请在服务器 SSH 执行(需 root): chown -R www:www '.base_path()
            .' 然后重新点击在线更新。'
        );
    }

    /**
     * 执行 composer install,并对"删除旧 vendor 文件失败"给出针对性诊断。
     */
    private function composerInstall(): string
    {
        try {
            return $this->shell(
                'cd '.base_path().' && COMPOSER_HOME=/tmp/composer composer install --no-dev --optimize-autoloader --no-interaction 2>&1',
                true,
                'Composer 安装失败(请确认服务器使用 Composer 2.2 或更高版本)'
            );
        } catch (\RuntimeException $e) {
            $message = $e->getMessage();
            // 删除旧 vendor 文件失败 → 属主权限问题,给出针对性指令
            if (preg_match('/failed to remove|Could not delete|Unable to remove|Permission denied|rm: cannot remove/i', $message)) {
                throw new \RuntimeException(
                    'Composer 删除旧依赖文件失败:vendor 内文件属主可能为 root(曾用 root 执行过 '
                    .'composer install),PHP 进程(www)无权删除。请在服务器 SSH 执行(需 root): '
                    .'chown -R www:www '.base_path()
                    .' 然后重新点击在线更新。原始输出: '.$message
                );
            }

            throw $e;
        }
    }

    /**
     * 检测当前环境是否能执行命令(proc_open 是 Symfony Process 的底层依赖)。
     */
    private function canExec(): bool
    {
        return function_exists('proc_open');
    }

    /**
     * git pull 前保护用户已修改的文件。
     *
     * 两种来源的本地改动都会导致 git pull 报
     * "Your local changes would be overwritten" 中断更新:
     *   1. 固定保护清单(.env 等用户真实配置,即使未被 git 跟踪)
     *   2. 用户手动改过的任意 git 跟踪文件(如 config/app.php 改时区)
     *
     * 策略:把这两类文件都备份到 storage,然后让工作区恢复干净(git checkout),
     * pull 完成后再把用户版本恢复回去 —— 用户改动不丢,更新不被阻塞。
     */
    private function preserveUserFiles(): void
    {
        // 1. 固定保护清单(.env 等核心配置,与 git 跟踪与否无关)
        //    权限不足时静默跳过(.env 在 .gitignore 里,git reset/clean 不会动它)
        foreach ($this->userProtectedFiles() as $relativePath) {
            $fullPath = base_path($relativePath);
            if (! @is_readable($fullPath)) {
                continue;
            }
            try {
                @copy($fullPath, $this->backupPath($relativePath));
            } catch (\Throwable) {
                // 备份失败不中断
            }
        }

        // 2. 自动检测所有本地有改动的 git 跟踪文件,备份用户版本
        //    覆盖用户改过 config/app.php、config/cache.php 等任意配置的场景
        try {
            $status = $this->shell('cd '.base_path().' && '.$this->gitCmd('status --porcelain').' 2>/dev/null');
            $dirtyFiles = $this->parseDirtyFiles($status);
            foreach ($dirtyFiles as $relativePath) {
                $fullPath = base_path($relativePath);
                if (! file_exists($fullPath) || ! @is_readable($fullPath)) {
                    continue;
                }
                $backup = $this->backupPath($relativePath);
                if (! file_exists($backup)) {
                    @copy($fullPath, $backup);
                }
            }
            // 3. 强制清理工作区:reset 已跟踪改动 + clean 未跟踪文件
            //    确保工作区与 HEAD 完全一致,后续 fetch+reset 不被任何本地状态阻塞
            $this->shell('cd '.base_path().' && '.$this->gitCmd('reset --hard HEAD').' 2>/dev/null');
            $this->cleanUntracked();
        } catch (\Throwable) {
            // 无法检测本地改动(如 shell 被禁用),回退到仅保护固定清单
        }
    }

    /**
     * 清理未跟踪的文件和目录(git clean -fd)。
     *
     * 之前构建的产物文件、临时文件等会成为未跟踪文件,
     * git 同步新版本时若同名文件进了仓库会报
     * "untracked working tree files would be overwritten" 中断更新。
     * 只清理未被 .gitignore 排除的未跟踪文件(不删 .env/storage/node_modules)。
     */
    private function cleanUntracked(): void
    {
        try {
            // -f 强制, -d 含目录; 不加 -x 以尊重 .gitignore
            $this->shell('cd '.base_path().' && '.$this->gitCmd('clean -fd').' 2>&1');
        } catch (\Throwable) {
            // 清理失败不中断
        }
    }

    /**
     * 解析 git status --porcelain 输出,提取有本地改动的文件路径。
     * 只取「工作区已修改」(M)和「已删除」(D)的文件,忽略未跟踪文件(??)。
     */
    private function parseDirtyFiles(string $status): array
    {
        $files = [];
        foreach (preg_split('/\r\n|\r|\n/', trim($status)) as $line) {
            if ($line === '') {
                continue;
            }
            // porcelain 格式: "XY path"(XY 各1字符,固定宽度,中间无额外空格)
            // 例: " M file.php"(工作区改)、"M  file.php"(暂存区改)、"?? file"(未跟踪)
            $flag = substr($line, 0, 2);
            $path = ltrim(substr($line, 2)); // 跳过 XY 两列,去掉路径前空格
            // 跳过未跟踪文件(??) 和暂存区新增(A)
            if (str_starts_with($flag, '?') || str_starts_with($flag, 'A')) {
                continue;
            }
            // 处理重命名 "R  old -> new" 取 new
            if (str_starts_with($flag, 'R') && str_contains($path, ' -> ')) {
                $path = trim(substr($path, strpos($path, ' -> ') + 4));
            }
            $path = trim($path, '"');
            // VERSION 是发布元数据,必须跟随目标提交,不能作为用户改动恢复。
            if ($path !== '' && $path !== 'VERSION') {
                $files[] = $path;
            }
        }

        return array_unique($files);
    }

    /**
     * 用户文件的备份路径(统一存放 storage/app/update_backups/,保留原始相对路径结构)。
     */
    private function backupPath(string $relativePath): string
    {
        $dir = storage_path('app/update_backups');
        $full = $dir.'/'.$relativePath;
        $parent = dirname($full);
        if (! is_dir($parent)) {
            @mkdir($parent, 0775, true);
        }

        return $full;
    }

    /**
     * git pull 后恢复用户文件(递归扫描备份目录,按相对路径还原)。
     */
    private function restoreUserFiles(): void
    {
        $dir = storage_path('app/update_backups');
        if (! is_dir($dir)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            if (! $file->isFile()) {
                continue;
            }
            // 备份内的相对路径 = 原始项目相对路径
            $relativePath = substr($file->getPathname(), strlen($dir.'/'));
            // 兼容旧更新器遗留的备份:旧 VERSION 不得覆盖本次拉取的新版本号。
            if ($relativePath === 'VERSION') {
                @unlink($file->getPathname());

                continue;
            }
            $fullPath = base_path($relativePath);
            // 确保目标目录存在(git pull 可能删除了旧的空目录)
            $parent = dirname($fullPath);
            if (! is_dir($parent)) {
                @mkdir($parent, 0775, true);
            }
            // 恢复失败(权限不足等)不能中断流程,也不能掩盖真正的错误:
            // .env 已在 .gitignore 中,git reset 不会动它,跳过恢复也不会丢配置。
            try {
                if (! @copy($file->getPathname(), $fullPath)) {
                    // @copy 失败时不抛异常,静默跳过并记日志便于排查
                    $this->log(storage_path('app/update.log'), "恢复用户文件跳过(权限不足): {$relativePath}");
                }
            } catch (\Throwable) {
                // copy 失败不中断
            }
            @unlink($file->getPathname());
        }
        // 清理空的备份目录树
        $this->removeEmptyDirs($dir);
    }

    /**
     * 递归删除空目录。
     */
    private function removeEmptyDirs(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        foreach (glob($dir.'/*') as $item) {
            if (is_dir($item)) {
                $this->removeEmptyDirs($item);
            }
        }
        @rmdir($dir);
    }

    /**
     * 需要固定保护、绝不能被 git pull 覆盖的文件(用户真实配置)。
     * 这些文件即使本地无改动也强制备份(.env 等),与 git 跟踪与否无关。
     */
    private function userProtectedFiles(): array
    {
        return ['.env', '.env.local'];
    }

    /**
     * 构造带安全上下文的 git 命令:内联 -c safe.directory=。
     *
     * 比 `git config --global --add safe.directory` 更可靠:后者写入 $HOME/.gitconfig,
     * 而 PHP-FPM 的 HOME 常不可写/未设置(宝塔环境),写入静默失败导致 git 操作
     * 报 "dubious ownership" 被拒。内联配置每次调用即时生效,不依赖任何配置文件。
     */
    private function gitCmd(string $command): string
    {
        return 'git -c safe.directory='.escapeshellarg(base_path()).' '.$command;
    }

    /**
     * 拉取 main 并显式刷新 origin/main,最终以本次 fetch 的 FETCH_HEAD 为准。
     */
    private function gitSyncCommand(): string
    {
        return 'cd '.base_path()
            .' && '.$this->gitCmd('fetch --force origin main:refs/remotes/origin/main').' 2>&1'
            .' && '.$this->gitCmd('reset --hard FETCH_HEAD').' 2>&1';
    }

    /**
     * 更新完成前核对 HEAD,避免远程引用陈旧时仍返回“更新成功”。
     */
    private function assertGitReferenceMatches(string $reference): void
    {
        $head = trim($this->shell(
            'cd '.base_path().' && '.$this->gitCmd('rev-parse HEAD').' 2>&1',
            true,
            '读取当前 Git 提交失败'
        ));
        $expected = trim($this->shell(
            'cd '.base_path().' && '.$this->gitCmd('rev-parse '.escapeshellarg($reference)).' 2>&1',
            true,
            '读取目标 Git 提交失败'
        ));

        if (! preg_match('/^[0-9a-f]{40}$/i', $head)
            || ! preg_match('/^[0-9a-f]{40}$/i', $expected)
            || ! hash_equals(strtolower($expected), strtolower($head))) {
            throw new \RuntimeException("Git 提交校验失败: 当前 HEAD={$head}, 目标={$expected}");
        }
    }

    /**
     * 解决 git "dubious ownership" 问题 + 修复 .git 目录权限。
     *
     * 两个层面的问题:
     * 1. dubious ownership — git 出于安全拒绝操作,加入 safe.directory 解决
     * 2. 文件权限不足 — 宝塔环境 .git/ 目录属主可能是 root,PHP-FPM 以 www 运行
     *    报 "insufficient permission for adding an object to repository database .git/objects"
     *    用 chmod -R 让 .git 和整个项目可读写解决
     */
    private function ensureGitSafeDirectory(): void
    {
        try {
            $base = base_path();
            // 1. 加入 safe.directory(解决 dubious ownership;PHP-FPM HOME 不可写时静默失败,由内联 -c 兜底)
            $this->shell('git config --global --add safe.directory '.escapeshellarg($base).' 2>/dev/null');

            // 2. 修复 .git 目录权限(解决 insufficient permission)
            //    宝塔: .git 属主可能是 root, PHP-FPM 以 www 运行 → chmod 放开读写
            $this->shell('chmod -R u+rwX,go+rwX '.escapeshellarg($base.'/.git').' 2>/dev/null');

            // 3. 如果当前用户不是目录属主(如 www 运行但属主是 root),
            //    尝试 chown(可能需要 sudo/root,失败则跳过,chmod 通常已够用)
            $this->shell('chown -R $(id -u):$(id -g) '.escapeshellarg($base.'/.git').' 2>/dev/null');
        } catch (\Throwable $e) {
            // 函数被禁用或权限不足 → 忽略,git 操作会自行报错
        }
    }

    /**
     * 确保 git remote 用 HTTPS。
     *
     * 重要: 本仓库是公开仓库,客户部署后 git pull 只需 HTTPS 只读,
     * 不需要任何认证/GitHub 账号/SSH key。
     *
     * 但开发环境本地 clone 用的是 SSH(git@github.com:...),
     * 客户的机器/容器里通常没有开发者的 SSH key,
     * 所以在线更新时自动把 SSH remote 切成 HTTPS,确保公开仓库免认证拉取。
     */
    private function ensureHttpsRemote(): void
    {
        try {
            $remote = trim($this->shell($this->gitCmd('remote get-url origin').' 2>/dev/null'));
            // 如果是 SSH(git@github.com:owner/repo.git),转 HTTPS
            if (preg_match('#git@github\.com:(.+)/(.+)\.git#', $remote, $m)) {
                $https = "https://github.com/{$m[1]}/{$m[2]}.git";
                $this->shell($this->gitCmd('remote set-url origin '.escapeshellarg($https)));
            }
        } catch (\Throwable $e) {
            // 函数被禁用时无法检测 remote,不中断(后续 git pull 会自行报错)
        }
    }

    /**
     * 物理删除 bootstrap/cache 里的编译缓存(config/routes/packages/services)。
     * 在 config:clear 自身可能因旧缓存崩溃时,这是唯一可靠的方法。
     * package:discover 会重建 packages.php + services.php。
     */
    private function clearBootstrapCache(): void
    {
        $cacheDir = base_path('bootstrap/cache');
        foreach (['config.php', 'routes-v7.php', 'packages.php', 'services.php'] as $file) {
            $path = $cacheDir.'/'.$file;
            if (file_exists($path)) {
                @unlink($path);
            }
        }
    }

    /**
     * 构建前端(pnpm 优先,失败则跳过——编译产物已在仓库)。
     */
    private function buildFrontend(string $logFile, string $dir): void
    {
        $path = base_path().'/'.$dir;
        $this->log($logFile, "构建前端({$dir})...");

        try {
            // 检测 pnpm 是否可用
            $pnpm = trim($this->shell('which pnpm 2>/dev/null'));
            if ($pnpm !== '') {
                $output = $this->shell("cd {$path} && pnpm install --frozen-lockfile 2>&1 && pnpm run build 2>&1");
                if (str_contains($output, 'error') || str_contains($output, 'ERR')) {
                    $this->log($logFile, "{$dir} 构建警告(使用仓库已有编译产物): ".substr($output, 0, 200));
                } else {
                    $this->log($logFile, "{$dir} 构建完成");
                }
            } else {
                // npm fallback
                $npm = trim($this->shell('which npm 2>/dev/null'));
                if ($npm !== '') {
                    $output = $this->shell("cd {$path} && npm ci --silent 2>&1 && npm run build 2>&1");
                    $this->log($logFile, "{$dir} npm 构建完成");
                } else {
                    $this->log($logFile, "{$dir} 无 pnpm/npm,使用仓库已有编译产物(public/{$dir}/)");
                }
            }
        } catch (\Throwable $e) {
            // 函数被禁用或构建失败,跳过(编译产物已在仓库)
            $this->log($logFile, "{$dir} 构建跳过({$e->getMessage()}),使用仓库已有编译产物");
        }
    }

    /**
     * 校验前端产物完整性:检查 index.html 引用的 JS/CSS 文件是否实际存在。
     *
     * 每次构建产物文件名带 hash(如 index-AbCd1234.js),如果 git pull/reset
     * 只更新了部分文件(如 index.html 更新了但 assets 没同步,或反之),
     * 浏览器加载的 index.html 会引用不存在的 JS/CSS → 404 白屏。
     *
     * 修复:如果校验失败(有引用的文件不存在),用 git checkout 强制同步
     * 整个 public/{dir}/ 目录到当前 HEAD 版本,确保 index.html 和 assets 一致。
     */
    private function verifyFrontendAssets(string $logFile, string $dir): void
    {
        $publicDir = base_path("public/{$dir}");
        $indexHtml = "{$publicDir}/index.html";

        if (! file_exists($indexHtml)) {
            $this->log($logFile, "{$dir} 校验跳过(无 index.html)");

            return;
        }

        $html = file_get_contents($indexHtml);
        // 提取所有 assets/*.js 和 *.css 引用
        preg_match_all('/(?:src|href)=(["|\x27])([^\\"|\x27]+\.(?:js|css))\1/', $html, $matches);
        // $matches[2] 是文件路径(不含引号)
        $assetUrls = $matches[2];

        $missing = [];
        foreach ($assetUrls as $assetUrl) {
            // assetUrl 可能是 /admin/assets/xxx.js 或 assets/xxx.js
            $relativePath = $assetUrl;
            // 去掉开头的 /{dir}/ 前缀,得到 public/{dir}/ 下的相对路径
            $relativePath = preg_replace('#^/?'.preg_quote($dir, '#').'/#', '', $relativePath);
            $fullPath = "{$publicDir}/{$relativePath}";

            if (! file_exists($fullPath)) {
                $missing[] = $assetUrl;
            }
        }

        if (empty($missing)) {
            $this->log($logFile, "{$dir} 产物校验通过(".count($assetUrls).' 个文件)');

            return;
        }

        // 校验失败:index.html 引用的文件缺失,强制从 git 同步整个目录
        $this->log($logFile, "{$dir} 产物校验失败! 缺失 ".count($missing).' 个文件: '.implode(', ', array_slice($missing, 0, 5)));
        $this->log($logFile, "{$dir} 强制从 git 同步 public/{$dir}/ ...");

        try {
            // git checkout HEAD -- public/{dir}/ 强制恢复整个目录
            $output = $this->shell('cd '.base_path().' && '.$this->gitCmd('checkout HEAD -- public/'.escapeshellarg($dir).'/').' 2>&1');
            $this->log($logFile, "{$dir} git 同步完成: ".trim($output));

            // 再次校验
            $stillMissing = [];
            foreach ($missing as $assetUrl) {
                $relativePath = preg_replace('#^/?'.preg_quote($dir, '#').'/#', '', $assetUrl);
                if (! file_exists("{$publicDir}/{$relativePath}")) {
                    $stillMissing[] = $assetUrl;
                }
            }

            if (empty($stillMissing)) {
                $this->log($logFile, "{$dir} 产物校验修复成功!");
            } else {
                $this->log($logFile, "{$dir} 产物校验仍有缺失(可能 HEAD 版本本身缺文件): ".implode(', ', array_slice($stillMissing, 0, 3)));
            }
        } catch (\Throwable $e) {
            $this->log($logFile, "{$dir} git 同步失败: ".$e->getMessage());
        }
    }
}
