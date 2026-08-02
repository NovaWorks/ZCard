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
