<?php

use Illuminate\Support\Facades\Route;

// sysadmin 后台 SPA:返回 admin/index.html(经 Laravel 以便 NoCacheHtml 中间件加 no-cache 头,
// 防止更新后浏览器用缓存的旧 index.html 引用已删除的 hash JS → 404 白屏)。
// 仅匹配根路径 /admin(子路由是 hash 路由 #/xxx,不请求服务端);静态 assets 由 web 服务器直接返回。
Route::get('/admin', function () {
    $path = public_path('admin/index.html');
    if (file_exists($path)) {
        return response()->file($path);
    }
    abort(404);
})->where('admin', 'admin');

// storefront SPA: 所有非 /admin、非 /api 路由返回 storefront 入口
// 编译产物在 public/storefront/index.html
Route::get('/{any?}', function () {
    $storefrontPath = public_path('storefront/index.html');

    if (! file_exists($storefrontPath)) {
        // 开发模式:storefront 未编译到 public/,提示用 vite dev
        return response('storefront 未编译。开发: cd storefront && pnpm dev | 生产: cd storefront && pnpm build', 200)
            ->header('Content-Type', 'text/plain; charset=UTF-8');
    }

    return response()->file($storefrontPath);
})->where('any', '^(?!api|admin|filament|storage|css|js|fonts|favicon\.ico|robots\.txt).*');
