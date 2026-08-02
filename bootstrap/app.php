<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

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
        $middleware->prepend(\App\Http\Middleware\EnsureInstalled::class);

        // API 路由加入 Session 支持(mews/captcha 验证码需要 session 存储)
        $middleware->api(prepend: [
            \Illuminate\Session\Middleware\StartSession::class,
            \App\Http\Middleware\MaintenanceMiddleware::class,
            \App\Http\Middleware\ResolveSubsite::class,
        ]);
        // 确保 StatefulApi 域配置存在(Sanctum SPA 认证也需要)
        $middleware->statefulApi();

        $middleware->alias([
            'display.currency' => \App\Http\Middleware\ResolveDisplayCurrency::class,
            'set.locale' => \App\Http\Middleware\SetLocale::class,
            'require.main.site' => \App\Http\Middleware\RequireMainSite::class,
            'admin.role' => \App\Http\Middleware\RequireAdminRole::class,
            'supply.auth' => \App\Http\Middleware\SupplyAuth::class,
            'supply.rate' => \App\Http\Middleware\SupplyRateLimit::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );

        // API 请求未认证时返回 401 JSON,而非重定向到不存在的 login 路由(API-first)。
        // 认证异常的默认处理会尝试 redirect()->route('login'),因 API 无 login 路由会抛
        // RouteNotFoundException —— 同时捕获这两类异常,对 api/* 一律返回 401 JSON。
        $exceptions->renderable(function (\Throwable $e, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }
            if ($e instanceof \Illuminate\Auth\AuthenticationException
                || $e instanceof \Symfony\Component\Routing\Exception\RouteNotFoundException) {
                return response()->json(['message' => '未认证,请提供有效的 API token'], 401);
            }
            return null;
        });
    })->create();
