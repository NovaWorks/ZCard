<?php

namespace App\Filament\Resources\Products\RelationManagers;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Table;

class SkusRelationManager extends RelationManager
{
    protected static string $relationship = 'skus';

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')->required()->label('规格名')->maxLength(60),
                TextInput::make('price')->numeric()->required()->prefix('分')->label('价格(分)'),
                Select::make('stock_type')
                    ->options(['card' => '卡密', 'url' => '链接', 'code' => '兑换码'])
                    ->placeholder('继承商品')->label('库存类型'),
                TextInput::make('sort')->numeric()->default(0)->label('排序'),
                Toggle::make('status')->default(true)->label('启用'),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->defaultSort('sort')
            ->columns([
                TextColumn::make('name')->label('规格名')->searchable(),
                TextColumn::make('price')->label('价格')->money('CNY', divideBy: 100, locale: 'zh_CN'),
                TextColumn::make('stock_type')->label('库存类型')->placeholder('继承商品')->toggleable(),
                TextColumn::make('sort')->label('排序')->alignRight()->toggleable(),
                ToggleColumn::make('status')->label('启用'),
            ])
            ->filters([
                //
            ])
            ->headerActions([
                CreateAction::make(),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
