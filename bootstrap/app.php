<?php

use App\Http\Middleware\AuditAdminAction;
use App\Http\Middleware\EnsureInstalled;
use App\Http\Middleware\MaintenanceMiddleware;
use App\Http\Middleware\NoCacheHtml;
use App\Http\Middleware\RequireActiveUser;
use App\Http\Middleware\RequireAdminRole;
use App\Http\Middleware\RequireMainSite;
use App\Http\Middleware\ResolveDisplayCurrency;
use App\Http\Middleware\ResolveSubsite;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\SetLocale;
use App\Http\Middleware\SupplyAuth;
use App\Http\Middleware\SupplyRateLimit;
use App\Http\Middleware\TrackVisitor;
use App\Models\SupplySource;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Session\Middleware\StartSession;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Exception\RouteNotFoundException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // 未安装拦截:最先执行,检测到未安装时降级 driver 并跳转 /install
        // 必须在 StartSession 之前,否则 session(driver=database) 查表会崩
        $middleware->prepend(EnsureInstalled::class);

        // SPA 入口 HTML 不缓存(防止更新后旧 index.html 引用已删除的 hash JS → 404 白屏)
        $middleware->append(NoCacheHtml::class);
        $middleware->append(SecurityHeaders::class);

        // 前台流量埋点(数据看板 PV/UV):web 组全局统计
        $middleware->append(TrackVisitor::class);

        // API 路由加入 Session 支持(mews/captcha 验证码需要 session 存储)
        // 注意:必须同时加 EncryptCookies —— 验证码图片走 web 组(有 EncryptCookies),
        // 若 api 组不加,图片请求写入的加密 session cookie 在下单校验时无法解密,
        // session 对不上 → 验证码恒定报错(主因 A:线上域名不在 SANCTUM_STATEFUL_DOMAINS 时的缺陷)。
        $middleware->api(prepend: [
            EncryptCookies::class,
            StartSession::class,
            MaintenanceMiddleware::class,
            ResolveSubsite::class,
        ]);
        // 确保 StatefulApi 域配置存在(Sanctum SPA 认证也需要)
        $middleware->statefulApi();

        $middleware->alias([
            'display.currency' => ResolveDisplayCurrency::class,
            'set.locale' => SetLocale::class,
            'require.main.site' => RequireMainSite::class,
            'admin.role' => RequireAdminRole::class,
            'active.user' => RequireActiveUser::class,
            'audit.admin' => AuditAdminAction::class,
            'supply.auth' => SupplyAuth::class,
            'supply.rate' => SupplyRateLimit::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );

        // API 请求未认证时返回 401 JSON,而非重定向到不存在的 login 路由(API-first)。
        // 认证异常的默认处理会尝试 redirect()->route('login'),因 API 无 login 路由会抛
        // RouteNotFoundException —— 同时捕获这两类异常,对 api/* 一律返回 401 JSON。
        $exceptions->renderable(function (Throwable $e, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }
            if ($e instanceof AuthenticationException
                || $e instanceof RouteNotFoundException) {
                return response()->json(['message' => '未认证,请提供有效的 API token'], 401);
            }

            // 隐式路由绑定找不到货源(如 /supply-sources/3/... 但 3 不存在):
            // 返回自诊断 JSON,列出可用 id,避免调用方面对无从查起的裸 404。
            // 注意:Handler 会先把 ModelNotFoundException 转成 NotFoundHttpException
            // 再交给 renderable 回调,故从 getPrevious() 取原始异常判断模型。
            if ($e instanceof NotFoundHttpException
                && $e->getPrevious() instanceof ModelNotFoundException
                && $e->getPrevious()->getModel() === SupplySource::class) {
                $ids = SupplySource::query()->pluck('id')->all();

                return response()->json([
                    'ok' => false,
                    'error' => '货源不存在: id='.($request->route('supplySource') ?? '?')
                        .'。可用货源 id: '.(implode(', ', $ids) ?: '暂无')
                        .'。请先 GET /api/admin/supply-sources 获取真实 id。',
                ], 404);
            }

            return null;
        });
    })->create();
