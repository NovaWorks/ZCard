<?php

return [

    'direction' => 'ltr',

    'skip_to_content' => [
        'label' => '跳转到内容',
    ],

    'actions' => [

        'billing' => [
            'label' => '管理订阅',
        ],

        'logout' => [
            'label' => '退出登录',
        ],

        'open_database_notifications' => [
            'label' => '通知',
            'label_with_unread_count' => '{1} 通知,:count 条未读|[2,*] 通知,:count 条未读',
        ],

        'open_user_menu' => [
            'label' => '用户菜单',
        ],

        'sidebar' => [

            'collapse' => [
                'label' => '收起侧栏',
            ],

            'expand' => [
                'label' => '展开侧栏',
            ],

        ],

        'theme_switcher' => [

            'label' => '主题',

            'dark' => [
                'label' => '暗黑模式',
            ],

            'light' => [
                'label' => '浅色模式',
            ],

            'system' => [
                'label' => '跟随系统',
            ],

        ],

    ],

    'navigation' => [
        'label' => '侧边栏导航',
    ],

    'topbar' => [
        'label' => '顶栏',
    ],

    'avatar' => [
        'alt' => ':name 的头像',
    ],

    'logo' => [
        'alt' => ':name 的 Logo',
    ],

    'tenant_menu' => [

        'search_field' => [
            'label' => '租户搜索',
            'placeholder' => '搜索',
        ],

    ],

];
