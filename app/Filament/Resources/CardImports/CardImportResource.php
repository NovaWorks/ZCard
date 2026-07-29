<?php

namespace App\Filament\Resources\CardImports;

use App\Filament\Resources\CardImports\Pages\CreateCardImport;
use App\Filament\Resources\CardImports\Pages\EditCardImport;
use App\Filament\Resources\CardImports\Pages\ListCardImports;
use App\Filament\Resources\CardImports\Schemas\CardImportForm;
use App\Filament\Resources\CardImports\Tables\CardImportsTable;
use App\Models\CardImport;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class CardImportResource extends Resource
{
    protected static ?string $model = CardImport::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowDownTray;

    public static function getNavigationGroup(): string | \UnitEnum | null
    {
        return '商品';
    }

    public static function getNavigationLabel(): string
    {
        return '导入批次';
    }

    public static function getModelLabel(): string
    {
        return '导入批次';
    }

    public static function getPluralModelLabel(): string
    {
        return '导入批次';
    }

    public static function canCreate(): bool
    {
        return false; // 批次由导入服务创建,不手动新建
    }

    public static function form(Schema $schema): Schema
    {
        return CardImportForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return CardImportsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCardImports::route('/'),
            'create' => CreateCardImport::route('/create'),
            'edit' => EditCardImport::route('/{record}/edit'),
        ];
    }
}
