<?php

namespace App\Filament\Resources\Products\Schemas;

use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\Textarea;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class ProductForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('基础信息')
                    ->schema([
                        TextInput::make('name')->required()->maxLength(150)->label('商品名'),
                        TextInput::make('slug')->required()->maxLength(150)->label('Slug')
                            ->hint('留空自动生成'),
                        Select::make('category_id')
                            ->relationship('category', 'name')
                            ->nullable()->label('分类'),
                        Textarea::make('description')->label('商品描述')->columnSpanFull(),
                    ])->columns(2),

                Section::make('价格与库存')
                    ->schema([
                        TextInput::make('price')->numeric()->required()->default(0)
                            ->prefix('分')->label('价格(分)'),
                        Select::make('stock_type')
                            ->options(['card' => '卡密', 'url' => '链接', 'code' => '兑换码'])
                            ->default('card')->label('库存类型'),
                        Toggle::make('stock_visible')->default(true)->label('显示库存数'),
                        TextInput::make('member_price')
                            ->hint('JSON: {等级:价格},Phase 3 生效')->label('会员价JSON'),
                    ])->columns(2),

                Section::make('配图')
                    ->schema([
                        FileUpload::make('cover')
                            ->image()->directory('products/covers')->disk('public')
                            ->imageEditor()->maxSize(5120)->label('封面图'),
                        FileUpload::make('images')
                            ->multiple()->image()->directory('products/gallery')->disk('public')
                            ->reorderable()->maxParallelUploads(3)->label('详情图(多图)'),
                    ])->columns(2),

                Section::make('上架设置')
                    ->schema([
                        Select::make('delivery_mode')
                            ->options(['status' => '保留(置used)', 'delete' => '物理删除'])
                            ->default('status')->label('发放模式'),
                        TextInput::make('sort')->numeric()->default(0)->label('排序'),
                        Toggle::make('status')->default(true)->label('上架'),
                    ])->columns(2),
            ]);
    }
}
