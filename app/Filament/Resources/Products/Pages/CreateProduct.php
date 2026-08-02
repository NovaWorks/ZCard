<?php

namespace App\Filament\Resources\Products\Pages;

use App\Filament\Resources\Products\ProductResource;
use Filament\Resources\Pages\CreateRecord;

class CreateProduct extends CreateRecord
{
    protected static string $resource = ProductResource::class;

    /**
     * 注入主站 merchant_id(与 Admin API 的硬编码 1 保持一致)。
     * products.merchant_id 为 NOT NULL 外键,否则 Filament 创建会因约束失败而无法入库。
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['merchant_id'] = 1;

        return $data;
    }
}
