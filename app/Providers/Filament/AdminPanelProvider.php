<?php

namespace App\Providers\Filament;

use App\Filament\Pages\Auth\Login;
use App\Filament\Pages\Dashboard;
use App\Filament\Pages\PaymentChannels;
use App\Filament\Pages\StorefrontSettings;
use App\Http\Middleware\ForcePasswordChange;
use BezhanSalleh\FilamentShield\FilamentShieldPlugin;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Panel;
use Filament\PanelProvider;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('filament')
            // 自定义登录页:补账号级失败锁定(M-7),与 API 登录共享锁定键
            ->login(Login::class)
            ->brandName('ZCard')
            // 主色亮蓝 #009EF7（P1-A.1）；darkMode 默认启用，右上角自动渲染明暗切换按钮
            ->colors([
                'primary' => '#009EF7',
                'success' => '#16a34a',
                'warning' => '#d97706',
                'danger' => '#ef4444',
            ])
            ->sidebarCollapsibleOnDesktop()
            ->navigationGroups([
                '商品',
                '系统',
            ])
            ->plugins([
                FilamentShieldPlugin::make()
                    ->navigationGroup('系统')
                    ->navigationIcon('heroicon-o-shield-check')
                    ->navigationLabel('角色权限'),
            ])
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            ->pages([
                Dashboard::class,
                StorefrontSettings::class,
                PaymentChannels::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\Filament\Widgets')
            ->widgets([])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                PreventRequestForgery::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
                ForcePasswordChange::class,
            ]);
    }
}
