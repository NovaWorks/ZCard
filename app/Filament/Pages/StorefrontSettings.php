<?php

namespace App\Filament\Pages;

use App\Models\Category;
use App\Support\StorefrontConfig;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\ToggleButtons;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class StorefrontSettings extends Page implements HasForms
{
    use InteractsWithForms;

    public static function getNavigationLabel(): string
    {
        return '店铺外观';
    }

    public static function getNavigationGroup(): string | \UnitEnum | null
    {
        return '系统';
    }

    public static function getNavigationSort(): ?int
    {
        return 1;
    }

    public static function getNavigationIcon(): string | \BackedEnum | null
    {
        return 'heroicon-o-paint-brush';
    }

    protected string $view = 'filament.pages.storefront-settings';

    public ?array $data = [];

    public function mount(): void
    {
        $this->form->fill(StorefrontConfig::all());
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                Section::make('商品列表布局')
                    ->schema([
                        ToggleButtons::make('category_nav_style')
                            ->options(['pills' => '顶部标签', 'sidebar' => '左侧树', 'combo' => '组合'])
                            ->grouped()->inline()->default('pills')->label('分类导航样式'),
                        ToggleButtons::make('list_default_view')
                            ->options(['grid' => '网格', 'list' => '列表', 'dual' => '双栏'])
                            ->grouped()->inline()->default('grid')->label('默认视图'),
                        ToggleButtons::make('grid_columns')
                            ->options([3 => '3', 4 => '4', 5 => '5'])
                            ->grouped()->inline()->default(4)->label('网格每行数'),
                        TextInput::make('page_size')->numeric()->default(12)->label('每页商品数'),
                        ToggleButtons::make('default_order')
                            ->options(['newest' => '最新', 'price_asc' => '价格升', 'price_desc' => '价格降', 'sort' => '手动'])
                            ->grouped()->inline()->default('newest')->label('默认排序'),
                    ])->columns(2),

                Section::make('展示项')
                    ->schema([
                        Toggle::make('show_stock')->label('显示库存数'),
                        Toggle::make('show_sales')->label('显示销量'),
                        Toggle::make('show_reviews')->label('显示评价'),
                        Toggle::make('allow_post_review')->label('允许用户发布评价'),
                        Toggle::make('review_need_audit')->label('评价需要审核'),
                    ])->columns(2),

                Section::make('首页推荐')
                    ->schema([
                        Toggle::make('show_featured')->label('启用首页推荐'),
                        TextInput::make('featured_count')->numeric()->default(8)->label('推荐位商品数'),
                    ])->columns(2),

                Section::make('下单设置(P1-C 收银台消费)')
                    ->schema([
                        Toggle::make('order_query_password')->label('启用查询密码')->hint('下单时设密码,凭邮箱+密码查单'),
                        Toggle::make('trade_captcha')->label('启用人机验证')->hint('下单时图形验证码'),
                    ])->columns(2),

                Section::make('热门标签')
                    ->schema([
                        Toggle::make('show_hot_tags')->label('显示热门标签'),
                        Select::make('hot_tag_categories')
                            ->options(Category::orderBy('sort')->pluck('name', 'id'))
                            ->multiple()->searchable()->label('热门标签分类'),
                    ])->columns(2),
            ]);
    }

    public function save(): void
    {
        $data = $this->form->getState();
        StorefrontConfig::setMany($data);
        Notification::make()->success()->title('已保存店铺外观设置')->send();
    }

    protected function getFormActions(): array
    {
        return [
            \Filament\Actions\Action::make('save')->label('保存')->submit('save'),
        ];
    }
}
