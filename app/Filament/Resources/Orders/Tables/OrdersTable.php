<?php

namespace App\Filament\Resources\Orders\Tables;

use App\Models\Order;
use App\Support\OrderService;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class OrdersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('order_no')->label('订单号')->searchable()->copyable(),
                TextColumn::make('product.name')->label('商品')->limit(20),
                TextColumn::make('quantity')->label('数量')->alignRight(),
                TextColumn::make('amount')->label('金额')->money('CNY', divideBy: 100, locale: 'zh_CN'),
                TextColumn::make('status')->badge()->label('状态')->colors([
                    'warning' => 'pending',
                    'success' => 'paid',
                    'gray' => 'closed',
                    'danger' => 'refunded',
                ])->formatStateUsing(fn (string $state): string => match ($state) {
                    'pending' => '待支付',
                    'paid' => '已支付',
                    'closed' => '已关闭',
                    'refunded' => '已退款',
                    default => $state,
                }),
                TextColumn::make('contact')->label('联系方式')->toggleable(),
                TextColumn::make('created_at')->dateTime()->label('下单时间')->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options([
                        'pending' => '待支付',
                        'paid' => '已支付',
                        'closed' => '已关闭',
                        'refunded' => '已退款',
                    ])
                    ->label('状态'),
            ])
            ->recordActions([
                Action::make('close')
                    ->label('关闭订单')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->visible(fn (Order $record) => $record->status === 'pending')
                    ->action(function (Order $record, OrderService $service) {
                        $service->closeOrder($record->id);
                        Notification::make()->success()->title('订单已关闭,卡密已释放')->send();
                    }),
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
