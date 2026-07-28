<?php

namespace App\Filament\Resources\Products\Schemas;

use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Repeater;
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

                Section::make('规格(SKU)')
                    ->schema([
                        Repeater::make('skus')
                            ->relationship()
                            ->schema([
                                TextInput::make('name')->required()->label('规格名(如月卡)'),
                                TextInput::make('price')->numeric()->required()->prefix('分')->label('价格(分)'),
                                Select::make('stock_type')
                                    ->options(['card' => '卡密', 'url' => '链接', 'code' => '兑换码'])
                                    ->placeholder('继承商品')->label('库存类型'),
                                TextInput::make('sort')->numeric()->default(0)->label('排序'),
                                Toggle::make('status')->default(true)->label('启用'),
                            ])
                            ->columns(3)
                            ->reorderable('sort')
                            ->defaultItems(0)
                            ->hint('留空则为单规格商品,用上方价格'),
                    ])
                    ->collapsed(),

                Section::make('自定义控件(下单时让顾客填写)')
                    ->schema([
                        Repeater::make('control_config')
                            ->schema([
                                Select::make('type')
                                    ->options([
                                        'text' => '文本', 'email' => '邮箱',
                                        'textarea' => '多行文本', 'select' => '下拉',
                                    ])->live()->required()->label('类型'),
                                TextInput::make('label')->required()->label('标签'),
                                TextInput::make('name')->required()->label('字段名'),
                                Toggle::make('required')->label('必填'),
                                TextInput::make('options')
                                    ->label('下拉选项(逗号分隔)')
                                    ->visible(fn ($get) => $get('type') === 'select'),
                            ])
                            ->columns(3)
                            ->defaultItems(0)
                            ->hint('产出 control_config JSON,P1-C 下单页渲染'),
                    ])
                    ->collapsed(),

                Section::make('营销虚拟数据')
                    ->schema([
                        Toggle::make('is_featured')->label('加入首页推荐'),
                        TextInput::make('virtual_sales')->numeric()->default(0)
                            ->label('虚拟销量基数(前台显示=真实+此数)'),
                        TextInput::make('virtual_reviews')
                            ->hint('JSON: {"rating":4.8,"count":156} 或含 list 数组')
                            ->label('虚拟评论JSON'),
                    ])
                    ->columns(2)
                    ->collapsed(),

                Section::make('限购')
                    ->schema([
                        TextInput::make('min_order')->numeric()->default(1)->label('最小购买量'),
                        TextInput::make('max_order')->numeric()->default(0)
                            ->label('最大购买量(0=不限)'),
                    ])
                    ->columns(2)
                    ->collapsed(),

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
