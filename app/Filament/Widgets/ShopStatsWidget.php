<?php

namespace App\Filament\Widgets;

use App\Models\Product;
use App\Models\User;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class ShopStatsWidget extends StatsOverviewWidget
{
    protected static ?int $sort = 1;

    protected function getStats(): array
    {
        return [
            Stat::make('商品总数', Product::count())
                ->description('全部商品')
                ->descriptionIcon('heroicon-m-shopping-bag')
                ->color('primary')
                ->icon('heroicon-o-shopping-bag'),
            Stat::make('用户总数', User::count())
                ->description('注册用户')
                ->descriptionIcon('heroicon-m-users')
                ->color('success')
                ->icon('heroicon-o-users'),
            Stat::make('今日订单', 0)
                ->description('P1-C 接入')
                ->descriptionIcon('heroicon-m-clipboard-document-list')
                ->color('warning')
                ->icon('heroicon-o-clipboard-document-list'),
            Stat::make('库存预警', 0)
                ->description('P1-B 接入')
                ->descriptionIcon('heroicon-m-exclamation-triangle')
                ->color('danger')
                ->icon('heroicon-o-exclamation-triangle'),
        ];
    }
}
