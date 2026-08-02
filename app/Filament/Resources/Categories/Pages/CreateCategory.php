<?php

namespace App\Filament\Resources\Categories\Pages;

use App\Filament\Resources\Categories\CategoryResource;
use Filament\Resources\Pages\CreateRecord;

class CreateCategory extends CreateRecord
{
    protected static string $resource = CategoryResource::class;

    /**
     * 注入主站 merchant_id(与 Admin API 的硬编码 1 保持一致)。
     * categories.merchant_id 为 NOT NULL 外键,否则 Filament 创建会因约束失败而无法入库。
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['merchant_id'] = 1;

        return $data;
    }
}
