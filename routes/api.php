<?php

use App\Http\Controllers\Api\CardController;
use App\Http\Controllers\Api\CardImportController;
use App\Http\Controllers\Api\CategoryController;
use App\Http\Controllers\Api\HealthController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\StorefrontSettingsController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/health', HealthController::class)->name('api.health');

// 前台认证(游客,不需 auth)
use App\Http\Controllers\Api\AuthController;

Route::post('/auth/register', [AuthController::class, 'register'])->name('api.auth.register');
Route::post('/auth/login', [AuthController::class, 'login'])->name('api.auth.login');

// 需登录
Route::middleware('auth:sanctum')->group(function () {
    Route::post('/auth/logout', [AuthController::class, 'logout'])->name('api.auth.logout');
    Route::get('/auth/me', [AuthController::class, 'me'])->name('api.auth.me');
});

Route::get('/categories', [CategoryController::class, 'index'])->name('api.categories');
Route::get('/products', [ProductController::class, 'index'])->name('api.products');
Route::get('/products/featured', [ProductController::class, 'featured'])->name('api.products.featured');
Route::get('/products/{slug}', [ProductController::class, 'show'])->name('api.products.show');
Route::get('/settings/storefront', [StorefrontSettingsController::class, 'show'])->name('api.settings.storefront');

// 卡密导入与库存(管理类,需 Sanctum token)— API-first:Filament 和 API 共用 Service 层
Route::middleware('auth:sanctum')->prefix('cards')->group(function () {
    Route::post('/import', [CardImportController::class, 'import'])->name('api.cards.import');
    Route::get('/import-status/{id}', [CardImportController::class, 'status'])->name('api.cards.import-status');
    Route::post('/import/{id}/revoke', [CardImportController::class, 'revoke'])->name('api.cards.revoke');
    Route::get('/export/{productId}', [CardController::class, 'export'])->name('api.cards.export');
});
Route::middleware('auth:sanctum')->get('/products/{id}/stock', [CardController::class, 'stock'])->name('api.products.stock');
Route::middleware('auth:sanctum')->get('/cards', [CardController::class, 'index'])->name('api.cards.index');

// 订单(游客,不需 auth)— API-first:前台和后台都调 OrderService
use App\Http\Controllers\Api\OrderController;

Route::post('/orders', [OrderController::class, 'create'])->name('api.orders.create');
Route::post('/orders/{orderNo}/mock-pay', [OrderController::class, 'mockPay'])->name('api.orders.mock-pay');
Route::get('/orders/query', [OrderController::class, 'query'])->name('api.orders.query');

// 支付(游客 + 回调,不需 auth)
use App\Http\Controllers\Api\PaymentController;

Route::get('/payments/channels', [PaymentController::class, 'channels'])->name('api.payments.channels');
Route::post('/payments/create', [PaymentController::class, 'create'])->name('api.payments.create');
Route::post('/payments/callback/{channel}', [PaymentController::class, 'callback'])->name('api.payments.callback');

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');
