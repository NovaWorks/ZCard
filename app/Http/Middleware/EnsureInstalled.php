<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * 未安装拦截中间件(全局,最先执行)。
 *
 * 问题背景:访问首页时 StartSession 中间件会读写 sessions 表,而安装前数据库
 * 还未 migrate,表不存在 → PDOException → 500 空响应。整条链路没有任何
 * "未安装 → 跳转安装向导"的兜底,客户 clone 后访问首页会直接看到 500。
 *
 * 本中间件在所有中间件之前执行:
 *  - 已安装(锁文件存在):直接放行,零行为变化。
 *  - 未安装:先把 session/cache/queue driver 降级为内存驱动(避免 StartSession
 *    碰数据库而崩溃),再放行安装向导相关请求与静态资源,其余浏览器请求 302
 *    跳转 /install、API 请求返回 503 JSON。
 */
class EnsureInstalled
{
    public function handle(Request $request, Closure $next): Response
    {
        // 锁文件存在 = 已安装,直接放行(最常见路径,先返回)
        if ($this->isInstalled()) {
            return $next($request);
        }

        // 未安装:降级为内存驱动,避免 StartSession 等 middleware 碰数据库而崩溃
        $this->downgradeDrivers();

        // .env 不存在时自动从 .env.example 复制(clone 后首次访问,git pull 不会覆盖)
        $this->ensureEnvFile();

        // 未安装时若 APP_KEY 为空,EncryptCookies / Session 中间件会抛异常导致 500,
        // 安装向导无法加载。这里兜底生成 key 并写入 .env(与安装向导后续的 key:generate 一致)
        $this->ensureAppKey();

        // 放行安装向导本身及其依赖的静态资源
        if ($this->isInstallRoute($request) || $this->isStaticAsset($request)) {
            return $next($request);
        }

        // 其余 API 请求:返回 503 提示未安装(前端/客户端可据此引导)
        if ($request->is('api/*')) {
            return response()->json([
                'message' => '系统尚未安装,请先完成安装向导',
                'install_required' => true,
                'install_url' => '/install',
            ], 503);
        }

        // 浏览器请求:跳转安装向导
        return redirect('/install');
    }

    /**
     * 是否已安装(锁文件存在)。
     * 与 InstallController / InstallCommand 的判定保持一致。
     */
    private function isInstalled(): bool
    {
        return file_exists(storage_path('app/installed'));
    }

    /**
     * 降级 driver 为内存驱动,确保后续中间件(StartSession 等)不碰数据库。
     * 仅在未安装的请求生命周期内生效,不持久化。
     */
    private function downgradeDrivers(): void
    {
        config([
            'session.driver' => 'array',
            'cache.default' => 'array',
            'queue.default' => 'sync',
        ]);
    }

    /**
     * .env 不存在时从 .env.example 复制一份。
     * .env 不进 git(含真实密钥),clone 后首次访问需自动创建,否则框架 500。
     * 已存在则不动(绝不覆盖用户已配置的真实凭据)。
     */
    private function ensureEnvFile(): void
    {
        $envPath = base_path('.env');
        if (file_exists($envPath)) {
            return;
        }

        $example = base_path('.env.example');
        if (file_exists($example)) {
            copy($example, $envPath);
        } else {
            // 极端情况:.env.example 也没有,创建空文件让框架能启动
            file_put_contents($envPath, '');
        }
    }

    /**
     * 兜底生成 APP_KEY。
     * 仓库 .env 的 APP_KEY 默认留空(便于开箱即用),但 EncryptCookies / Session
     * 中间件初始化时需要有效 key,否则框架直接 500,安装向导无法加载。
     * 仅在未安装且 key 为空时执行:生成并写入 .env,同时注入当前进程 config。
     */
    private function ensureAppKey(): void
    {
        if (! empty(config('app.key'))) {
            return;
        }

        $key = 'base64:'.base64_encode(random_bytes(32));
        $this->writeEnv('APP_KEY', $key);
        config(['app.key' => $key]);
    }

    /**
     * 写入 .env(若 key 已存在则替换,否则追加)。.env 不存在时从 .env.example 创建。
     */
    private function writeEnv(string $key, string $value): void
    {
        $path = base_path('.env');
        if (! file_exists($path)) {
            $example = base_path('.env.example');
            if (file_exists($example)) {
                copy($example, $path);
            } else {
                file_put_contents($path, '');
            }
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

    /**
     * 安装向导相关请求(页面 + 三个 API)。
     */
    private function isInstallRoute(Request $request): bool
    {
        return $request->is('install') || $request->is('api/install/*') || $request->is('up');
    }

    /**
     * 静态资源(catch-all 返回 SPA HTML 时,前端需要加载 JS/CSS/字体)。
     * /storefront/* 与 /admin/* 是已提交到仓库的编译产物。
     */
    private function isStaticAsset(Request $request): bool
    {
        return $request->is('css/*', 'js/*', 'fonts/*', 'storefront/*', 'admin/*')
            || $request->is('favicon.ico', 'robots.txt');
    }
}
