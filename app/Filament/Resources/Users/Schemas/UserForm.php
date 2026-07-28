<?php

namespace App\Filament\Resources\Users\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class UserForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('username')->required()->maxLength(50)->label('用户名'),
                TextInput::make('name')->maxLength(100)->label('姓名'),
                TextInput::make('email')->email()->required()->maxLength(255)->label('邮箱'),
                Select::make('status')->options([1 => '正常', 0 => '禁用'])->default(1)->label('状态'),
                TextInput::make('balance')->numeric()->default(0)->label('余额(分)'),
                Select::make('roles')
                    ->relationship('roles', 'name')
                    ->multiple()
                    ->preload()
                    ->label('角色'),
            ]);
    }
}

