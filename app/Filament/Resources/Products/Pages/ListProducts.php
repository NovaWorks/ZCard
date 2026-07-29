<?php

namespace App\Filament\Resources\Products\Pages;

use App\Filament\Resources\Products\ProductResource;
use App\Models\Product;
use App\Support\CardImportService;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\Facades\Storage;

class ListProducts extends ListRecords
{
    protected static string $resource = ProductResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('importCards')
                ->label('导入卡密')
                ->icon('heroicon-o-arrow-up-tray')
                ->schema([
                    Select::make('product_id')
                        ->label('目标商品')
                        ->options(Product::orderBy('name')->pluck('name', 'id'))
                        ->required(),
                    FileUpload::make('file')
                        ->label('或上传文件(txt/csv,每行一个)')
                        ->acceptedFileTypes(['text/plain', 'text/csv'])
                        ->disk('local'),
                    Textarea::make('content')
                        ->label('或直接粘贴卡密(每行一个)')
                        ->rows(8),
                ])
                ->action(function (array $data) {
                    // 文件优先,否则用粘贴内容
                    $content = $data['content'] ?? '';
                    if (! empty($data['file'])) {
                        $path = is_array($data['file']) ? $data['file'][0] : $data['file'];
                        $fileContent = Storage::disk('local')->get($path);
                        if ($fileContent) {
                            $content = $fileContent;
                        }
                    }
                    if (trim($content) === '') {
                        Notification::make()->title('请上传文件或粘贴卡密内容')->danger()->send();
                        return;
                    }
                    $service = app(CardImportService::class);
                    $import = $service->import(
                        $data['product_id'],
                        auth()->id(),
                        $content,
                        ['source' => 'filament']
                    );
                    $fresh = $import->fresh();
                    Notification::make()
                        ->title('导入完成')
                        ->body("成功 {$fresh->success_count} / 失败 {$fresh->failed_count}(总数 {$fresh->total})")
                        ->success()
                        ->send();
                }),
            CreateAction::make(),
        ];
    }
}
