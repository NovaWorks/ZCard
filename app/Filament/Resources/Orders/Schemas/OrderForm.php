<?php

namespace App\Filament\Resources\Orders\Schemas;

use Filament\Forms\Components\Select;
use Filament\Schemas\Schema;

class OrderForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('status')
                    ->options([
                        'pending' => '待支付',
                        'paid' => '已支付',
                        'closed' => '已关闭',
                        'refunded' => '已退款',
                    ])
                    ->required(),
            ]);
    }
}
