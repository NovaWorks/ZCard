<?php

namespace App\Filament\Resources\CardImports\Pages;

use App\Filament\Resources\CardImports\CardImportResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListCardImports extends ListRecords
{
    protected static string $resource = CardImportResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
