<?php

namespace App\Filament\Resources\Cards\Schemas;

use App\Models\Card;
use Filament\Forms\Components\Select;
use Filament\Schemas\Schema;

class CardForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('status')
                    ->options([
                        Card::STATUS_UNUSED => '未使用',
                        Card::STATUS_LOCKED => '锁定中',
                        Card::STATUS_USED => '已使用',
                        Card::STATUS_DISABLED => '已禁用',
                    ])
                    ->required(),
            ]);
    }
}
