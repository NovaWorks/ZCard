<?php

namespace App\Filament\Resources\Cards\Tables;

use App\Models\Card;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Contracts\View\View;

class CardsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('id')->sortable()->label('ID'),
                TextColumn::make('product.name')->label('商品')->searchable(),
                TextColumn::make('content')->limit(20)->label('卡密(加密)'),
                TextColumn::make('status')->badge()->label('状态')->colors([
                    'success' => Card::STATUS_UNUSED,
                    'warning' => Card::STATUS_LOCKED,
                    'gray' => Card::STATUS_USED,
                    'danger' => Card::STATUS_DISABLED,
                ])->formatStateUsing(fn (string $state): string => match ($state) {
                    Card::STATUS_UNUSED => '未使用',
                    Card::STATUS_LOCKED => '锁定中',
                    Card::STATUS_USED => '已使用',
                    Card::STATUS_DISABLED => '已禁用',
                    default => $state,
                }),
                TextColumn::make('import.source')->label('来源')->toggleable(),
                TextColumn::make('created_at')->dateTime()->label('导入时间')->sortable(),
            ])
            ->filters([
                SelectFilter::make('product_id')
                    ->relationship('product', 'name')
                    ->label('商品'),
                SelectFilter::make('status')
                    ->options([
                        Card::STATUS_UNUSED => '未使用',
                        Card::STATUS_LOCKED => '锁定中',
                        Card::STATUS_USED => '已使用',
                        Card::STATUS_DISABLED => '已禁用',
                    ])
                    ->label('状态'),
            ])
            ->recordActions([
                Action::make('viewPlaintext')
                    ->label('查看明文')
                    ->icon('heroicon-o-eye')
                    ->modalHeading('卡密明文')
                    ->modalSubmitAction(false)
                    ->modalContent(fn (Card $record): View => view(
                        'filament.cards.plaintext',
                        ['plaintext' => $record->decryptedContent()],
                    )),
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
