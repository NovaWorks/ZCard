<?php

namespace App\Filament\Resources\Merchants\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class MerchantForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')->required()->maxLength(100)->label('商户名称'),
                TextInput::make('slug')->required()->maxLength(100)->label('Slug'),
                Select::make('status')->options([1 => '正常', 0 => '禁用'])->default(1)->label('状态'),
                TextInput::make('commission_rate')
                    ->numeric()
                    ->default(0)
                    ->step(0.0001)
                    ->label('佣金率(0.0000~9.9999)'),
            ]);
    }
}

