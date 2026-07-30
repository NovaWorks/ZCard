<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CardController;
use App\Http\Controllers\Api\CardImportController;
use App\Http\Controllers\Api\CategoryController;
use App\Http\Controllers\Api\HealthController;
use App\Http\Controllers\Api\OrderController;
use App\Http\Controllers\Api\PaymentController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\ReviewController;
use App\Http\Controllers\Api\StorefrontSettingsController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/health', HealthController::class)->name('api.health');

// 前台认证(游客,不需 auth)
Route::post('/auth/register', [AuthController::class, 'register'])->name('api.auth.register');
Route::post('/auth/login', [AuthController::class, 'login'])->name('api.auth.login');

// 需登录
Route::middleware('auth:sanctum')->group(function () {
    Route::post('/auth/logout', [AuthController::class, 'logout'])->name('api.auth.logout');
    Route::get('/auth/me', [AuthController::class, 'me'])->name('api.auth.me');
    Route::get('/orders/mine', [OrderController::class, 'myOrders'])->name('api.orders.mine');
});

// 后台管理 API(Sanctum token)
use App\Http\Controllers\Api\Admin\CardController as AdminCardController;
use App\Http\Controllers\Api\Admin\CategoryController as AdminCategoryController;
use App\Http\Controllers\Api\Admin\OrderController as AdminOrderController;
use App\Http\Controllers\Api\Admin\PaymentChannelController as AdminPaymentChannelController;
use App\Http\Controllers\Api\Admin\ProductController as AdminProductController;
use App\Http\Controllers\Api\Admin\ProductSkuController as AdminProductSkuController;
use App\Http\Controllers\Api\Admin\SettingController as AdminSettingController;
use App\Http\Controllers\Api\Admin\UploadController as AdminUploadController;
use App\Http\Controllers\Api\Admin\UserController as AdminUserController;

Route::middleware('auth:sanctum')->prefix('admin')->group(function () {
    Route::apiResource('products', AdminProductController::class);
    Route::get('products/{productId}/skus', [AdminProductSkuController::class, 'index']);
    Route::post('products/skus', [AdminProductSkuController::class, 'store']);
    Route::put('products/skus/{id}', [AdminProductSkuController::class, 'update']);
    Route::delete('products/skus/{id}', [AdminProductSkuController::class, 'destroy']);
    Route::post('upload/image', [AdminUploadController::class, 'image']);
    Route::apiResource('categories', AdminCategoryController::class)->except(['show']);
    Route::get('categories/all', [AdminCategoryController::class, 'all']);

    // 用户管理(CRUD + 角色分配)
    Route::apiResource('users', AdminUserController::class);

    // 订单管理(只读列表/详情 + 手动关单)
    Route::apiResource('orders', AdminOrderController::class)->only(['index', 'show']);
    Route::post('orders/{id}/close', [AdminOrderController::class, 'close']);

    // 卡密管理(不含明文,安全)
    Route::get('cards', [AdminCardController::class, 'index']);
    Route::post('cards/import', [AdminCardController::class, 'import']);
    Route::post('cards/disable', [AdminCardController::class, 'disable']);
    Route::get('cards/import-batches', [AdminCardController::class, 'importBatches']);

    // 支付通道配置
    Route::apiResource('payment-channels', AdminPaymentChannelController::class)->only(['index', 'update']);
    Route::get('payment-channels/{id}/config-fields', [AdminPaymentChannelController::class, 'configFields']);

    // 店铺外观配置
    Route::get('settings', [AdminSettingController::class, 'index']);
    Route::put('settings', [AdminSettingController::class, 'update']);
});

Route::get('/categories', [CategoryController::class, 'index'])->name('api.categories');
Route::get('/products', [ProductController::class, 'index'])->name('api.products');
Route::get('/products/featured', [ProductController::class, 'featured'])->name('api.products.featured');

// 评价:商品评价列表(必须在 /products/{slug} 之前注册才能匹配)
Route::get('/products/{slug}/reviews', [ReviewController::class, 'productReviews'])->name('api.reviews.product');

Route::get('/products/{slug}', [ProductController::class, 'show'])->name('api.products.show');

// 提交评价(需登录)
Route::middleware('auth:sanctum')->post('/reviews', [ReviewController::class, 'store'])->name('api.reviews.store');
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
Route::post('/orders', [OrderController::class, 'create'])->name('api.orders.create');
Route::post('/orders/{orderNo}/mock-pay', [OrderController::class, 'mockPay'])->name('api.orders.mock-pay');
Route::get('/orders/query', [OrderController::class, 'query'])->name('api.orders.query');

// 支付(游客 + 回调,不需 auth)
Route::get('/payments/channels', [PaymentController::class, 'channels'])->name('api.payments.channels');
Route::post('/payments/create', [PaymentController::class, 'create'])->name('api.payments.create');
Route::post('/payments/callback/{channel}', [PaymentController::class, 'callback'])->name('api.payments.callback');

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');
