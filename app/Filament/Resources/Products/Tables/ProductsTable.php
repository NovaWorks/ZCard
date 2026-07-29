<?php

namespace App\Filament\Resources\Products\Tables;

use App\Models\Card;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ForceDeleteBulkAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;

class ProductsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('sort')
            ->modifyQueryUsing(fn (\Illuminate\Database\Eloquent\Builder $query) =>
                $query->withCount(['cards as available_stock_count' => fn ($q) => $q->where('status', Card::STATUS_UNUSED)])
            )
            ->columns([
                ImageColumn::make('cover')->label('封面')->circular(),
                TextColumn::make('name')->label('商品名')->searchable()->limit(30),
                TextColumn::make('category.name')->label('分类')->toggleable(),
                TextColumn::make('price')->label('价格')->money('CNY', divideBy: 100, locale: 'zh_CN'),
                TextColumn::make('available_stock_count')->label('可用库存')->sortable()->alignRight(),
                IconColumn::make('is_featured')->boolean()->label('推荐')->toggleable(),
                ToggleColumn::make('status')->label('上架'),
                TextColumn::make('sort')->label('排序')->alignRight()->toggleable(),
            ])
            ->filters([
                TrashedFilter::make(),
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                    ForceDeleteBulkAction::make(),
                    RestoreBulkAction::make(),
                ]),
            ]);
    }
}
