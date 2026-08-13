<?php

namespace App\Providers;

use App\Events\OrderPaid;
use App\Listeners\FetchFromUpstreamOnOrderPaid;
use App\Listeners\UpgradeUserGroupOnOrderPaid;
use App\Support\AppHelper;
use App\Support\CommissionService;
use App\Support\DeliveryService;
use App\Support\SubsiteSettlementService;
use App\Support\WorkerRuntime;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // 记录当前 PHP 进程启动时的代码版本，供队列探针识别旧 worker。
        WorkerRuntime::boot(AppHelper::version());

        // 订单支付成功 → 自动发货(Laravel 13 用 Event::listen 注册)
        Event::listen(OrderPaid::class, [DeliveryService::class, 'handle']);
        Event::listen(OrderPaid::class, [FetchFromUpstreamOnOrderPaid::class, 'handle']);
        Event::listen(OrderPaid::class, [CommissionService::class, 'handle']);
        Event::listen(OrderPaid::class, [SubsiteSettlementService::class, 'handle']);
        Event::listen(OrderPaid::class, [UpgradeUserGroupOnOrderPaid::class, 'handle']);
    }
}
