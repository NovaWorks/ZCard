<?php

namespace App\Providers;

use App\Events\OrderPaid;
use App\Support\DeliveryService;
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
        // 订单支付成功 → 自动发货(Laravel 13 用 Event::listen 注册)
        Event::listen(OrderPaid::class, [DeliveryService::class, 'handle']);
    }
}
