<?php

namespace App\Filament\Resources\CardImports\Tables;

use App\Models\CardImport;
use App\Support\CardImportService;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class CardImportsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('id')->sortable()->label('批次'),
                TextColumn::make('product.name')->label('商品'),
                TextColumn::make('source')->label('来源'),
                TextColumn::make('total')->label('总数')->alignRight(),
                TextColumn::make('success_count')->label('成功')->alignRight()->color('success'),
                TextColumn::make('failed_count')->label('失败')->alignRight()
                    ->color(fn ($state) => $state > 0 ? 'danger' : null),
                TextColumn::make('status')->badge()->label('状态')->colors([
                    'success' => 'completed',
                    'warning' => 'running',
                    'danger' => 'failed',
                    'gray' => 'revoked',
                ])->formatStateUsing(fn (string $state): string => match ($state) {
                    'completed' => '已完成',
                    'running' => '处理中',
                    'failed' => '失败',
                    'revoked' => '已撤销',
                    default => $state,
                }),
                TextColumn::make('error_log')->label('失败明细')
                    ->formatStateUsing(fn ($state) => is_array($state) && count($state) ? count($state) . ' 条' : '-')
                    ->toggleable(),
                TextColumn::make('created_at')->dateTime()->label('时间')->sortable(),
            ])
            ->filters([
                //
            ])
            ->recordActions([
                Action::make('revoke')
                    ->label('撤销未用')
                    ->icon('heroicon-o-arrow-uturn-left')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading('撤销导入')
                    ->modalDescription('将删除本批次所有"未使用"的卡密,已使用/锁定的不受影响。')
                    ->visible(fn (CardImport $record) => $record->status !== 'revoked')
                    ->action(function (CardImport $record, CardImportService $service) {
                        $deleted = $service->revokeImport($record->id);
                        Notification::make()->success()->title("已撤销 {$deleted} 张未用卡密")->send();
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
