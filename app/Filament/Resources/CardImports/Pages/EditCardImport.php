<?php

namespace App\Filament\Resources\CardImports\Pages;

use App\Filament\Resources\CardImports\CardImportResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditCardImport extends EditRecord
{
    protected static string $resource = CardImportResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
