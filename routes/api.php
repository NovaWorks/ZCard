<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CardController;
use App\Http\Controllers\Api\CardImportController;
use App\Http\Controllers\Api\CaptchaController;
use App\Http\Controllers\Api\CategoryController;
use App\Http\Controllers\Api\CouponController;
use App\Http\Controllers\Api\CurrencyController;
use App\Http\Controllers\Api\HealthController;
use App\Http\Controllers\Api\OrderController;
use App\Http\Controllers\Api\PaymentController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\ReviewController;
use App\Http\Controllers\Api\StorefrontSettingsController;
use App\Http\Controllers\Api\SubsiteConsoleController;
use App\Http\Controllers\Api\WithdrawalController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/health', HealthController::class)->name('api.health');

// 前台认证(游客,不需 auth)
Route::post('/auth/register', [AuthController::class, 'register'])->name('api.auth.register');
Route::post('/auth/login', [AuthController::class, 'login'])->name('api.auth.login');
Route::post('/auth/send-reset-code', [AuthController::class, 'sendResetCode'])->name('api.auth.send-reset-code');
Route::post('/auth/reset-password', [AuthController::class, 'resetPassword'])->name('api.auth.reset-password');

// 需登录
Route::middleware('auth:sanctum')->group(function () {
    Route::post('/auth/logout', [AuthController::class, 'logout'])->name('api.auth.logout');
    Route::get('/auth/me', [AuthController::class, 'me'])->name('api.auth.me');
    Route::get('/orders/mine', [OrderController::class, 'myOrders'])->name('api.orders.mine');
    // 三级分销(推广中心)
    Route::get('/distribution/stats', [\App\Http\Controllers\Api\DistributionController::class, 'stats'])->name('api.distribution.stats');
    Route::get('/distribution/referrals', [\App\Http\Controllers\Api\DistributionController::class, 'referrals'])->name('api.distribution.referrals');
    Route::get('/distribution/commissions', [\App\Http\Controllers\Api\DistributionController::class, 'commissions'])->name('api.distribution.commissions');
    // 提现(需登录)
    Route::post('/withdrawals', [WithdrawalController::class, 'request'])->name('api.withdrawals.request');
    Route::get('/withdrawals/history', [WithdrawalController::class, 'history'])->name('api.withdrawals.history');

    // 分站主自助控制台(只在主站)
    Route::middleware('require.main.site')->prefix('subsite-console')->group(function () {
        Route::get('/', [SubsiteConsoleController::class, 'mySubsite']);
        Route::get('/finance', [SubsiteConsoleController::class, 'finance']);
        Route::get('/ledger', [SubsiteConsoleController::class, 'ledger']);
        Route::post('/domains', [SubsiteConsoleController::class, 'bindDomain']);
        Route::get('/product-settings', [SubsiteConsoleController::class, 'productSettings']);
        Route::post('/product-settings', [SubsiteConsoleController::class, 'upsertProductSetting']);
        Route::post('/withdrawals', [SubsiteConsoleController::class, 'requestWithdrawal']);
        Route::put('/branding', [SubsiteConsoleController::class, 'updateBranding']);
    });
});

// 后台管理 API(Sanctum token)
use App\Http\Controllers\Api\Admin\CardController as AdminCardController;
use App\Http\Controllers\Api\Admin\CategoryController as AdminCategoryController;
use App\Http\Controllers\Api\Admin\CurrencyController as AdminCurrencyController;
use App\Http\Controllers\Api\Admin\OrderController as AdminOrderController;
use App\Http\Controllers\Api\Admin\BillController as AdminBillController;
use App\Http\Controllers\Api\Admin\CommissionController as AdminCommissionController;
use App\Http\Controllers\Api\Admin\CouponController as AdminCouponController;
use App\Http\Controllers\Api\Admin\PaymentChannelController as AdminPaymentChannelController;
use App\Http\Controllers\Api\Admin\WithdrawalController as AdminWithdrawalController;
use App\Http\Controllers\Api\Admin\ProductController as AdminProductController;
use App\Http\Controllers\Api\Admin\ProductSkuController as AdminProductSkuController;
use App\Http\Controllers\Api\Admin\SettingController as AdminSettingController;
use App\Http\Controllers\Api\Admin\SubsiteController as AdminSubsiteController;
use App\Http\Controllers\Api\Admin\UploadController as AdminUploadController;
use App\Http\Controllers\Api\Admin\UserController as AdminUserController;
use App\Http\Controllers\Api\Admin\UserGroupController as AdminUserGroupController;

Route::middleware(['auth:sanctum', 'admin.role'])->prefix('admin')->group(function () {
    // stats/batch 必须在 apiResource 之前(否则 stats 被当成 {product} 参数)
    Route::get('products/stats', [AdminProductController::class, 'stats']);
    Route::post('products/batch', [AdminProductController::class, 'batch']);
    Route::apiResource('products', AdminProductController::class);
    Route::get('products/{productId}/skus', [AdminProductSkuController::class, 'index']);
    Route::post('products/skus', [AdminProductSkuController::class, 'store']);
    Route::put('products/skus/{id}', [AdminProductSkuController::class, 'update']);
    Route::delete('products/skus/{id}', [AdminProductSkuController::class, 'destroy']);
    Route::post('upload/image', [AdminUploadController::class, 'image']);
    Route::apiResource('categories', AdminCategoryController::class)->except(['show']);
    Route::get('categories/all', [AdminCategoryController::class, 'all']);
    Route::post('categories/sort', [AdminCategoryController::class, 'updateSort']);
    Route::post('categories/batch', [AdminCategoryController::class, 'batchUpdate']);

    // 用户管理(CRUD + 角色分配)
    // stats 必须在 apiResource 之前注册，否则会被 GET /users/{user} 当作参数吃掉。
    Route::get('users/stats', [AdminUserController::class, 'stats']);
    Route::apiResource('users', AdminUserController::class);

    // 会员等级(user_groups)管理
    Route::apiResource('user-groups', AdminUserGroupController::class)->only(['index', 'store', 'update', 'destroy']);

    // 账单管理(列表/统计/手动调账)
    Route::get('bills/stats', [AdminBillController::class, 'stats']);
    Route::get('bills', [AdminBillController::class, 'index']);
    Route::post('bills/adjust', [AdminBillController::class, 'adjust']);

    // 分销佣金管理(列表/统计)
    Route::get('commissions/stats', [AdminCommissionController::class, 'stats']);
    Route::get('commissions', [AdminCommissionController::class, 'index']);

    // 提现管理(列表/统计/审核)
    Route::get('withdrawals/stats', [AdminWithdrawalController::class, 'stats']);
    Route::get('withdrawals', [AdminWithdrawalController::class, 'index']);
    Route::post('withdrawals/{id}/approve', [AdminWithdrawalController::class, 'approve']);
    Route::post('withdrawals/{id}/reject', [AdminWithdrawalController::class, 'reject']);

    // 优惠券管理(CRUD)
    Route::get('coupons/stats', [AdminCouponController::class, 'stats']);
    Route::post('coupons/toggle/{id}', [AdminCouponController::class, 'toggle']);
    Route::apiResource('coupons', AdminCouponController::class)->only(['index', 'store', 'destroy']);

    // 订单管理(列表/详情/关单/统计/导出/清理)
    Route::get('orders/stats', [AdminOrderController::class, 'stats']);
    Route::get('orders/export', [AdminOrderController::class, 'export']);
    Route::post('orders/clear', [AdminOrderController::class, 'clear']);
    Route::apiResource('orders', AdminOrderController::class)->only(['index', 'show']);
    Route::post('orders/{id}/close', [AdminOrderController::class, 'close']);

    // 卡密管理(不含明文,安全)
    // 注意:静态路由(export/stats/destroy)必须先于资源式 cards 注册,
    // 否则会被 GET /cards/{id} 这种带参数的解析吃掉。
    Route::get('cards/export', [AdminCardController::class, 'export']);
    Route::get('cards/stats', [AdminCardController::class, 'stats']);
    Route::get('cards/{id}/reveal', [AdminCardController::class, 'reveal']);
    Route::put('cards/{id}', [AdminCardController::class, 'update']);
    Route::get('cards', [AdminCardController::class, 'index']);
    Route::post('cards/import', [AdminCardController::class, 'import']);
    Route::post('cards/disable', [AdminCardController::class, 'disable']);
    Route::post('cards/enable', [AdminCardController::class, 'enable']);
    Route::post('cards/lock', [AdminCardController::class, 'lock']);
    Route::post('cards/unlock', [AdminCardController::class, 'unlock']);
    Route::post('cards/mark-sold', [AdminCardController::class, 'markSold']);
    Route::post('cards/destroy', [AdminCardController::class, 'destroy']);
    Route::get('cards/import-batches', [AdminCardController::class, 'importBatches']);

    // 支付通道配置
    Route::apiResource('payment-channels', AdminPaymentChannelController::class)->only(['index', 'update']);
    Route::get('payment-channels/{id}/config-fields', [AdminPaymentChannelController::class, 'configFields']);

    // 店铺外观配置
    Route::get('settings', [AdminSettingController::class, 'index']);
    Route::put('settings', [AdminSettingController::class, 'update']);

    // 货币管理(CRUD)
    Route::apiResource('currencies', AdminCurrencyController::class)->except(['show']);

    // 分站管理
    Route::get('subsites', [AdminSubsiteController::class, 'index']);
    Route::post('subsites', [AdminSubsiteController::class, 'store']);
    Route::put('subsites/domains/{domain}', [AdminSubsiteController::class, 'updateDomain']);
    Route::get('subsites/{merchant}/product-settings', [AdminSubsiteController::class, 'productSettings']);
    Route::post('subsites/product-settings', [AdminSubsiteController::class, 'upsertProductSetting']);
});

// 公开货币列表(供前台货币切换器,不需要 display.currency 中间件)
Route::get('/currencies', [CurrencyController::class, 'index'])->name('api.currencies.index');

Route::middleware(['display.currency', 'set.locale'])->group(function () {
    Route::get('/categories', [CategoryController::class, 'index'])->name('api.categories');
    Route::get('/products', [ProductController::class, 'index'])->name('api.products');
    Route::get('/products/featured', [ProductController::class, 'featured'])->name('api.products.featured');

    // 评价:商品评价列表(必须在 /products/{slug} 之前注册才能匹配)
    Route::get('/products/{slug}/reviews', [ReviewController::class, 'productReviews'])->name('api.reviews.product');

    Route::get('/products/{slug}', [ProductController::class, 'show'])->name('api.products.show');
});

// 提交评价(需登录)
Route::middleware('auth:sanctum')->post('/reviews', [ReviewController::class, 'store'])->name('api.reviews.store');
Route::get('/settings/storefront', [StorefrontSettingsController::class, 'show'])->middleware(['display.currency', 'set.locale'])->name('api.settings.storefront');

// 验证码(图形验证码,基于 mews/captcha)
Route::get('/captcha/config', [CaptchaController::class, 'config'])->name('api.captcha.config');

// 优惠券验证(下单前预览折扣)
Route::post('/coupons/validate', [CouponController::class, 'validateCode'])->name('api.coupons.validate');
Route::get('/captcha/{scene?}', function (Request $request, $scene = 'default') {
    return response()->json(['src' => captcha_src($scene)]);
})->name('api.captcha.src');

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
Route::post('/orders', [OrderController::class, 'create'])->middleware(['display.currency', 'set.locale'])->name('api.orders.create');
Route::post('/orders/{orderNo}/mock-pay', [OrderController::class, 'mockPay'])->middleware(['display.currency', 'set.locale'])->name('api.orders.mock-pay');
Route::get('/orders/query', [OrderController::class, 'query'])->middleware(['display.currency', 'set.locale'])->name('api.orders.query');

// 支付(游客 + 回调,不需 auth)
Route::get('/payments/channels', [PaymentController::class, 'channels'])->name('api.payments.channels');
Route::post('/payments/create', [PaymentController::class, 'create'])->name('api.payments.create');
Route::post('/payments/callback/{channel}', [PaymentController::class, 'callback'])->name('api.payments.callback');

// 支付同步跳回(第三方支付完成后浏览器跳转,重定向到前台结果页)
// 驱动用 payment.return / payment.cancel / payment.notify 命名路由拼接跳回地址
Route::get('/payments/return/{code}', function (Request $request, string $code) {
    $query = http_build_query(array_filter([
        'code' => $code,
        'order_no' => $request->query('order_no'),
        'session_id' => $request->query('session_id'),
        'out_trade_no' => $request->query('out_trade_no'),
    ]));
    return redirect('/pay/result?' . $query);
})->name('payment.return');

Route::get('/payments/cancel/{code}', function (Request $request, string $code) {
    $query = http_build_query(array_filter([
        'code' => $code,
        'order_no' => $request->query('order_no'),
        'out_trade_no' => $request->query('out_trade_no'),
    ]));
    return redirect('/pay/result?status=cancel&' . $query);
})->name('payment.cancel');

// payment.notify = 异步通知,与 callback 同义(部分第三方用 notify_url 命名)
Route::post('/payments/notify/{channel}', [PaymentController::class, 'callback'])->name('payment.notify');

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');
