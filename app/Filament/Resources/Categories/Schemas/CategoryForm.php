<?php

namespace App\Filament\Resources\Categories\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class CategoryForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('parent_id')
                    ->label('父分类')
                    ->relationship('parent', 'name', ignoreRecord: true)
                    ->placeholder('顶级分类')
                    ->nullable(),
                TextInput::make('name')->required()->maxLength(100)->label('名称'),
                TextInput::make('slug')->required()->maxLength(100)->label('Slug')
                    ->hint('唯一标识,留空自动生成'),
                TextInput::make('sort')->numeric()->default(0)->label('排序'),
                Toggle::make('status')->default(true)->label('启用'),
            ]);
    }
}
