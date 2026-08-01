<?php

use Illuminate\Support\Facades\Route;

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
