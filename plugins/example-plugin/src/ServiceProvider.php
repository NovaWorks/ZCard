<?php

namespace App\Plugins\ExamplePlugin;

use Illuminate\Support\ServiceProvider as BaseServiceProvider;

/**
 * 示例插件入口（占位）。
 * Phase 0：不会被主程序加载。Phase 2：由插件系统按 plugin.json 的 hooks 注册监听。
 */
class ServiceProvider extends BaseServiceProvider
{
    public function register(): void
    {
        // Phase 2 实现：注册路由/视图/Hook
    }

    public function boot(): void
    {
        // Phase 2 实现：监听事件
    }
}
