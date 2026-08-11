<?php

use App\Http\Controllers\Api\Admin\BillController as AdminBillController;
use App\Http\Controllers\Api\Admin\CardController as AdminCardController;
use App\Http\Controllers\Api\Admin\CategoryController as AdminCategoryController;
use App\Http\Controllers\Api\Admin\CommissionController as AdminCommissionController;
use App\Http\Controllers\Api\Admin\CouponController as AdminCouponController;
use App\Http\Controllers\Api\Admin\CurrencyController as AdminCurrencyController;
use App\Http\Controllers\Api\Admin\DashboardController as AdminDashboardController;
use App\Http\Controllers\Api\Admin\MediaCategoryController as AdminMediaCategoryController;
use App\Http\Controllers\Api\Admin\MediaController as AdminMediaController;
use App\Http\Controllers\Api\Admin\OrderController as AdminOrderController;
use App\Http\Controllers\Api\Admin\PaymentChannelController as AdminPaymentChannelController;
use App\Http\Controllers\Api\Admin\ProductController as AdminProductController;
use App\Http\Controllers\Api\Admin\ProductSkuController as AdminProductSkuController;
use App\Http\Controllers\Api\Admin\RechargeController as AdminRechargeController;
use App\Http\Controllers\Api\Admin\ReviewController as AdminReviewController;
use App\Http\Controllers\Api\Admin\SettingController as AdminSettingController;
use App\Http\Controllers\Api\Admin\SubsiteController as AdminSubsiteController;
use App\Http\Controllers\Api\Admin\SupplierAccountController;
use App\Http\Controllers\Api\Admin\SupplierPriceController;
use App\Http\Controllers\Api\Admin\SupplySourceController;
use App\Http\Controllers\Api\Admin\UpdateController as AdminUpdateController;
use App\Http\Controllers\Api\Admin\UploadController as AdminUploadController;
use App\Http\Controllers\Api\Admin\UserController as AdminUserController;
use App\Http\Controllers\Api\Admin\UserGroupController as AdminUserGroupController;
use App\Http\Controllers\Api\Admin\WithdrawalController as AdminWithdrawalController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CaptchaController;
use App\Http\Controllers\Api\CardController;
use App\Http\Controllers\Api\CardImportController;
use App\Http\Controllers\Api\CategoryController;
use App\Http\Controllers\Api\CouponController;
use App\Http\Controllers\Api\CurrencyController;
use App\Http\Controllers\Api\DistributionController;
use App\Http\Controllers\Api\HealthController;
use App\Http\Controllers\Api\MySupplyController;
use App\Http\Controllers\Api\OrderController;
use App\Http\Controllers\Api\PaymentController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\RechargeController;
use App\Http\Controllers\Api\ReviewController;
use App\Http\Controllers\Api\StorefrontSettingsController;
use App\Http\Controllers\Api\SubsiteConsoleController;
use App\Http\Controllers\Api\Supply\SupplyController;
use App\Http\Controllers\Api\Supply\SupplyOrderController;
use App\Http\Controllers\Api\Supply\SupplyProductController;
use App\Http\Controllers\Api\WithdrawalController;
use App\Http\Controllers\InstallController;
use App\Support\CaptchaService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/health', HealthController::class)->name('api.health');

// 安装向导(无需认证,安装前可用)
Route::prefix('install')->group(function () {
    Route::get('/status', [InstallController::class, 'status'])->name('api.install.status');
    Route::post('/test-db', [InstallController::class, 'testDb'])->name('api.install.test-db');
    Route::post('/run', [InstallController::class, 'run'])->name('api.install.run');
});

// 前台认证(游客,不需 auth)— 限流防暴力破解
Route::middleware('throttle:5,1')->post('/auth/register', [AuthController::class, 'register'])->name('api.auth.register');
Route::middleware('throttle:5,1')->post('/auth/login', [AuthController::class, 'login'])->name('api.auth.login');
Route::middleware('throttle:3,1')->post('/auth/send-reset-code', [AuthController::class, 'sendResetCode'])->name('api.auth.send-reset-code');
Route::middleware('throttle:5,1')->post('/auth/reset-password', [AuthController::class, 'resetPassword'])->name('api.auth.reset-password');

// 需登录
Route::middleware(['auth:sanctum', 'active.user'])->group(function () {
    Route::post('/auth/logout', [AuthController::class, 'logout'])->name('api.auth.logout');
    Route::get('/auth/me', [AuthController::class, 'me'])->name('api.auth.me');
    Route::put('/auth/profile', [AuthController::class, 'updateProfile'])->name('api.auth.profile');
    Route::put('/auth/password', [AuthController::class, 'updatePassword'])->name('api.auth.password');
    Route::get('/orders/mine', [OrderController::class, 'myOrders'])->name('api.orders.mine');
    // 三级分销(推广中心)
    Route::get('/distribution/stats', [DistributionController::class, 'stats'])->name('api.distribution.stats');
    Route::get('/distribution/referrals', [DistributionController::class, 'referrals'])->name('api.distribution.referrals');
    Route::get('/distribution/commissions', [DistributionController::class, 'commissions'])->name('api.distribution.commissions');
    // 提现(需登录)
    Route::post('/withdrawals', [WithdrawalController::class, 'request'])->name('api.withdrawals.request');
    Route::get('/withdrawals/history', [WithdrawalController::class, 'history'])->name('api.withdrawals.history');

    // 充值到余额(需登录):创建充值单 / 历史 / 状态查询
    Route::post('/recharges', [RechargeController::class, 'create'])->name('api.recharges.create');
    Route::get('/recharges/history', [RechargeController::class, 'history'])->name('api.recharges.history');
    Route::get('/recharges/{rechargeNo}/status', [RechargeController::class, 'status'])->name('api.recharges.status');

    // 自助供货对接(个人中心 API 对接):获取/查看供货凭证
    Route::prefix('supplier-account')->group(function () {
        Route::get('/me', [MySupplyController::class, 'me'])->name('api.mysupply.me');
        Route::get('/secret', [MySupplyController::class, 'showSecret'])->name('api.mysupply.secret');
        Route::post('/regenerate', [MySupplyController::class, 'regenerate'])->name('api.mysupply.regenerate');
    });

    // 分站主自助控制台(只在主站)
    Route::middleware('require.main.site')->prefix('subsite-console')->group(function () {
        Route::get('/', [SubsiteConsoleController::class, 'mySubsite']);
        Route::get('/finance', [SubsiteConsoleController::class, 'finance']);
        Route::get('/ledger', [SubsiteConsoleController::class, 'ledger']);
        Route::post('/domains', [SubsiteConsoleController::class, 'bindDomain']);
        Route::post('/domains/{domainId}/verify', [SubsiteConsoleController::class, 'verifyDomain']);
        Route::get('/domains/{domainId}/instructions', [SubsiteConsoleController::class, 'domainInstructions']);
        Route::get('/product-settings', [SubsiteConsoleController::class, 'productSettings']);
        Route::post('/product-settings', [SubsiteConsoleController::class, 'upsertProductSetting']);
        Route::post('/withdrawals', [SubsiteConsoleController::class, 'requestWithdrawal']);
        Route::put('/branding', [SubsiteConsoleController::class, 'updateBranding']);
        Route::get('/orders', [SubsiteConsoleController::class, 'orders']);
    });
});

// 后台管理 API(Sanctum token)
Route::middleware(['auth:sanctum', 'active.user', 'admin.role', 'audit.admin'])->prefix('admin')->group(function () {
    // 仪表盘(概览/趋势/排行)
    Route::get('dashboard/overview', [AdminDashboardController::class, 'overview']);
    Route::get('dashboard/trends', [AdminDashboardController::class, 'trends']);
    Route::get('dashboard/top-products', [AdminDashboardController::class, 'topProducts']);
    Route::get('dashboard/top-channels', [AdminDashboardController::class, 'topChannels']);

    // 在线更新(检查版本/执行更新/日志)
    Route::get('update/check', [AdminUpdateController::class, 'check']);
    Route::get('update/versions', [AdminUpdateController::class, 'versions']);
    Route::post('update/run', [AdminUpdateController::class, 'update']);
    Route::post('update/rollback', [AdminUpdateController::class, 'rollback']);
    Route::get('update/log', [AdminUpdateController::class, 'getLog']);

    // stats/batch 必须在 apiResource 之前(否则 stats 被当成 {product} 参数)
    Route::get('products/stats', [AdminProductController::class, 'stats']);
    Route::post('products/batch', [AdminProductController::class, 'batch']);
    Route::apiResource('products', AdminProductController::class);
    Route::get('products/{productId}/skus', [AdminProductSkuController::class, 'index']);
    Route::post('products/skus', [AdminProductSkuController::class, 'store']);
    Route::put('products/skus/{id}', [AdminProductSkuController::class, 'update']);
    Route::delete('products/skus/{id}', [AdminProductSkuController::class, 'destroy']);
    Route::post('upload/image', [AdminUploadController::class, 'image']);

    // 素材管理(spec 2026-08-06)
    // 注意:静态路由(batch-*)必须先于资源路由 media/{id} 注册,否则会被参数解析吃掉。
    Route::get('media', [AdminMediaController::class, 'index']);
    Route::post('media/upload', [AdminMediaController::class, 'upload']);
    Route::post('media/batch-delete', [AdminMediaController::class, 'batchDelete']);
    Route::post('media/batch-move', [AdminMediaController::class, 'batchMove']);
    Route::delete('media/{id}', [AdminMediaController::class, 'destroy']);

    // 素材分类
    Route::get('media-categories', [AdminMediaCategoryController::class, 'index']);
    Route::post('media-categories', [AdminMediaCategoryController::class, 'store']);
    Route::put('media-categories/{id}', [AdminMediaCategoryController::class, 'update']);
    Route::delete('media-categories/{id}', [AdminMediaCategoryController::class, 'destroy']);
    Route::post('media-categories/{id}/move', [AdminMediaCategoryController::class, 'move']);

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

    // 评价审核管理(列表/统计/通过/拒绝)
    Route::get('reviews/stats', [AdminReviewController::class, 'stats']);
    Route::get('reviews', [AdminReviewController::class, 'index']);
    Route::post('reviews/{id}/approve', [AdminReviewController::class, 'approve']);
    Route::post('reviews/{id}/reject', [AdminReviewController::class, 'reject']);
    Route::get('commissions', [AdminCommissionController::class, 'index']);

    // 提现管理(列表/统计/审核)
    Route::get('withdrawals/stats', [AdminWithdrawalController::class, 'stats']);
    Route::get('withdrawals', [AdminWithdrawalController::class, 'index']);
    Route::post('withdrawals/{id}/approve', [AdminWithdrawalController::class, 'approve']);
    Route::post('withdrawals/{id}/reject', [AdminWithdrawalController::class, 'reject']);

    // 优惠券管理(CRUD + 导出 + 批量删除)
    Route::get('coupons/export', [AdminCouponController::class, 'export']);
    Route::get('coupons/stats', [AdminCouponController::class, 'stats']);
    Route::post('coupons/toggle/{id}', [AdminCouponController::class, 'toggle']);
    Route::post('coupons/batch-delete', [AdminCouponController::class, 'batchDelete']);
    Route::apiResource('coupons', AdminCouponController::class)->only(['index', 'store', 'destroy']);

    // 订单管理(列表/详情/关单/统计/导出/清理)
    Route::get('orders/stats', [AdminOrderController::class, 'stats']);
    Route::get('orders/export', [AdminOrderController::class, 'export']);
    Route::post('orders/clear', [AdminOrderController::class, 'clear']);
    Route::apiResource('orders', AdminOrderController::class)->only(['index', 'show']);
    Route::post('orders/{id}/close', [AdminOrderController::class, 'close']);
    Route::post('orders/{id}/fulfill', [AdminOrderController::class, 'fulfill']);

    // 充值单管理(列表/详情/统计)
    // 注意:stats 必须先于 apiResource 注册,否则会被 GET /recharges/{recharge} 当作参数吃掉。
    Route::get('recharges/stats', [AdminRechargeController::class, 'stats']);
    Route::apiResource('recharges', AdminRechargeController::class)->only(['index', 'show']);

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
    Route::get('payment-channels/drivers', [AdminPaymentChannelController::class, 'drivers']);
    Route::apiResource('payment-channels', AdminPaymentChannelController::class)->only(['index', 'store', 'update', 'destroy']);
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

    // 供货账号管理(spec §7.1)
    Route::apiResource('supplier-accounts', SupplierAccountController::class)
        ->parameter('supplier-accounts', 'supplierAccount');
    Route::post('supplier-accounts/{supplierAccount}/reset-secret', [SupplierAccountController::class, 'resetSecret']);
    Route::post('supplier-accounts/{supplierAccount}/recharge', [SupplierAccountController::class, 'recharge']);
    Route::post('supplier-accounts/{supplierAccount}/adjust', [SupplierAccountController::class, 'adjust']);
    Route::get('supplier-accounts/{supplierAccount}/ledger', [SupplierAccountController::class, 'ledger']);
    // 专属定价(账号维度)
    Route::get('supplier-accounts/{supplierAccount}/prices', [SupplierPriceController::class, 'indexForAccount']);
    Route::put('supplier-accounts/{supplierAccount}/prices', [SupplierPriceController::class, 'updateForAccount']);
    Route::delete('supplier-accounts/{supplierAccount}/prices/{priceId}', [SupplierPriceController::class, 'destroyForAccount']);
    // 专属定价(商品维度)
    Route::get('products/{product}/supply-prices', [SupplierPriceController::class, 'indexForProduct']);
    Route::put('products/{product}/supply-prices', [SupplierPriceController::class, 'updateForProduct']);

    // 货源对接设置(spec §6.1)
    // 注意:静态 GET supply-sources/drivers 必须先于 apiResource 注册,
    // 否则会被 apiResource 的 show({supplySource}) 当作参数吃掉。
    Route::get('supply-sources/drivers', [SupplySourceController::class, 'drivers']);
    Route::apiResource('supply-sources', SupplySourceController::class)
        ->parameter('supply-sources', 'supplySource');
    Route::post('supply-sources/{supplySource}/test', [SupplySourceController::class, 'test']);
    Route::post('supply-sources/{supplySource}/sync', [SupplySourceController::class, 'sync']);
    Route::get('supply-sources/{supplySource}/sync-status', [SupplySourceController::class, 'syncStatus']);
    // 商品预览(实时拉取上游,供勾选) + 勾选导入 + 调试
    Route::get('supply-sources/{supplySource}/products/preview', [SupplySourceController::class, 'previewProducts']);
    Route::post('supply-sources/{supplySource}/products/import', [SupplySourceController::class, 'importProducts']);
    Route::get('supply-sources/{supplySource}/products/debug', [SupplySourceController::class, 'debugProducts']);
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
Route::middleware(['auth:sanctum', 'active.user'])->post('/reviews', [ReviewController::class, 'store'])->name('api.reviews.store');
// 可评价状态(需登录):静态路由先于资源式注册
Route::middleware(['auth:sanctum', 'active.user'])->get('/reviews/eligibility/{productId}', [ReviewController::class, 'eligibility'])->name('api.reviews.eligibility');
Route::get('/settings/storefront', [StorefrontSettingsController::class, 'show'])->middleware(['display.currency', 'set.locale'])->name('api.settings.storefront');

// 验证码(图形验证码,基于 mews/captcha)
Route::get('/captcha/config', [CaptchaController::class, 'config'])->name('api.captcha.config');

// 优惠券验证(下单前预览折扣)
Route::post('/coupons/validate', [CouponController::class, 'validateCode'])->name('api.coupons.validate');

Route::get('/captcha/{scene?}', function (Request $request, $scene = 'default') {
    return response()->json(CaptchaService::create($scene));
})->name('api.captcha.src');

// 卡密导入与库存(管理类,需 Sanctum token)— API-first:Filament 和 API 共用 Service 层
Route::middleware(['auth:sanctum', 'active.user', 'admin.role', 'audit.admin'])->prefix('cards')->group(function () {
    Route::post('/import', [CardImportController::class, 'import'])->name('api.cards.import');
    Route::get('/import-status/{id}', [CardImportController::class, 'status'])->name('api.cards.import-status');
    Route::post('/import/{id}/revoke', [CardImportController::class, 'revoke'])->name('api.cards.revoke');
    Route::get('/export/{productId}', [CardController::class, 'export'])->name('api.cards.export');
});
Route::middleware(['auth:sanctum', 'active.user', 'admin.role'])->get('/products/{id}/stock', [CardController::class, 'stock'])->name('api.products.stock');
Route::middleware(['auth:sanctum', 'active.user', 'admin.role'])->get('/cards', [CardController::class, 'index'])->name('api.cards.index');

// 订单(游客,不需 auth)— API-first:前台和后台都调 OrderService
Route::post('/orders', [OrderController::class, 'create'])->middleware(['display.currency', 'set.locale'])->name('api.orders.create');
Route::post('/orders/batch', [OrderController::class, 'batch'])->middleware(['display.currency', 'set.locale'])->name('api.orders.batch');
Route::post('/orders/{orderNo}/mock-pay', [OrderController::class, 'mockPay'])->middleware(['display.currency', 'set.locale'])->name('api.orders.mock-pay');
Route::get('/orders/query', [OrderController::class, 'query'])->middleware(['display.currency', 'set.locale'])->name('api.orders.query');

// 支付(游客 + 回调,不需 auth)
Route::get('/payments/channels', [PaymentController::class, 'channels'])->name('api.payments.channels');
Route::post('/payments/create', [PaymentController::class, 'create'])->name('api.payments.create');
Route::post('/payments/batch-create', [PaymentController::class, 'batchCreate'])->name('api.payments.batch-create');
// 余额支付(需登录,校验订单归属;订单管理可见 payment_channel=balance)
Route::post('/payments/balance', [PaymentController::class, 'balancePay'])->middleware(['display.currency', 'set.locale'])->name('api.payments.balance');
Route::post('/payments/balance-batch', [PaymentController::class, 'balanceBatchPay'])->middleware(['display.currency', 'set.locale'])->name('api.payments.balance-batch');
// 支付回调:易支付(889 等)文档明确异步通知走 GET,部分平台走 POST,统一用 any 兼容。
// 驱动 verifyCallback 内部已 array_merge(query, post) 兼容两种传参方式。
Route::any('/payments/callback/{channel}', [PaymentController::class, 'callback'])->name('api.payments.callback');

// 支付同步跳回(第三方支付完成后浏览器跳转,重定向到前台结果页)
// 驱动用 payment.return / payment.cancel / payment.notify 命名路由拼接跳回地址
// order_no 参数实际为商户业务单号:RCH 前缀=充值单,跳充值页;否则为订单,跳支付结果页
Route::get('/payments/return/{code}', function (Request $request, string $code) {
    $bizNo = $request->query('order_no') ?: $request->query('out_trade_no');
    if (is_string($bizNo) && str_starts_with($bizNo, 'RCH')) {
        return redirect('/recharge/result?recharge_no='.urlencode($bizNo));
    }
    $query = http_build_query(array_filter([
        'code' => $code,
        'order_no' => $request->query('order_no'),
        'session_id' => $request->query('session_id'),
        'out_trade_no' => $request->query('out_trade_no'),
    ]));

    return redirect('/pay/result?'.$query);
})->name('payment.return');

Route::get('/payments/cancel/{code}', function (Request $request, string $code) {
    $bizNo = $request->query('order_no') ?: $request->query('out_trade_no');
    if (is_string($bizNo) && str_starts_with($bizNo, 'RCH')) {
        return redirect('/recharge/result?status=cancel&recharge_no='.urlencode($bizNo));
    }
    $query = http_build_query(array_filter([
        'code' => $code,
        'order_no' => $request->query('order_no'),
        'out_trade_no' => $request->query('out_trade_no'),
    ]));

    return redirect('/pay/result?status=cancel&'.$query);
})->name('payment.cancel');

// payment.notify = 异步通知,与 callback 同义(部分第三方用 notify_url 命名)。
// 易支付(889)异步通知走 GET,其他平台可能 POST,统一 any 兼容。
Route::any('/payments/notify/{channel}', [PaymentController::class, 'callback'])->name('payment.notify');

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware(['auth:sanctum', 'active.user']);

// ===== 供货 API(对外供货,spec §4.3) =====
Route::prefix('supply')->middleware(['supply.auth', 'supply.rate'])
    ->group(function () {
        Route::post('ping', [SupplyController::class, 'ping'])->name('api.supply.ping');
        Route::get('categories', [SupplyProductController::class, 'categories'])->name('api.supply.categories');
        Route::get('products', [SupplyProductController::class, 'index'])->name('api.supply.products.index');
        Route::get('products/{id}', [SupplyProductController::class, 'show'])->name('api.supply.products.show')
            ->whereNumber('id');
        Route::get('products/{id}/stock', [SupplyProductController::class, 'stock'])->name('api.supply.products.stock')
            ->whereNumber('id');
        Route::post('orders', [SupplyOrderController::class, 'create'])->name('api.supply.orders.create');
        Route::get('orders/{id}', [SupplyOrderController::class, 'show'])->name('api.supply.orders.show')
            ->whereNumber('id');
        Route::post('orders/{id}/cancel', [SupplyOrderController::class, 'cancel'])->name('api.supply.orders.cancel')
            ->whereNumber('id');
    });
// 回调端点不经过 supply.auth(上游用各自协议签名,由驱动 verifyCallback 处理)
Route::post('supply/callback', [SupplyController::class, 'callback'])->name('api.supply.callback');
