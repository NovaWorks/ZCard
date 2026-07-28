<?php

namespace App\Filament\Widgets;

use Filament\Widgets\ChartWidget;

class SalesChartWidget extends ChartWidget
{
    protected ?string $heading = '近 7 日销售趋势';

    protected static ?int $sort = 2;

    protected function getData(): array
    {
        // 占位数据，P1-C 订单就位后接真实销售额
        return [
            'datasets' => [
                [
                    'label' => '销售额(元)',
                    'data' => [125.8, 262.5, 138.0, 375.2, 255.0, 488.8, 370.0],
                    'backgroundColor' => '#009EF7',
                    'borderRadius' => 6,
                ],
            ],
            'labels' => ['周一', '周二', '周三', '周四', '周五', '周六', '今天'],
        ];
    }

    protected function getType(): string
    {
        return 'bar';
    }
}
