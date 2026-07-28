<?php

namespace App\Filament\Pages;

use App\Filament\Widgets\LatestOrdersWidget;
use App\Filament\Widgets\SalesChartWidget;
use App\Filament\Widgets\ShopStatsWidget;
use Filament\Pages\Dashboard as BaseDashboard;

class Dashboard extends BaseDashboard
{
    public function getWidgets(): array
    {
        return [
            ShopStatsWidget::class,
            SalesChartWidget::class,
            LatestOrdersWidget::class,
        ];
    }

    public function getColumns(): int | array
    {
        return [
            'default' => 1,
            'md' => 2,
        ];
    }
}
