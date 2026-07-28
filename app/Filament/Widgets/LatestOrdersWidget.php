<?php

namespace App\Filament\Widgets;

use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;

class LatestOrdersWidget extends TableWidget
{
    protected static ?string $heading = '最近订单';

    protected static ?int $sort = 3;

    public function table(Table $table): Table
    {
        // P1-C 订单模型就位后切换为 Order::query()->latest()->limit(5)
        return $table
            ->query(fn (): Builder => \App\Models\Product::query()->whereRaw('1=0')) // 空态占位
            ->emptyStateHeading('暂无订单')
            ->emptyStateDescription('订单数据将在 P1-C（订单核心）完成后显示')
            ->columns([
                TextColumn::make('id')->label('订单号'),
            ]);
    }
}
