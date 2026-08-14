<?php

/**
 * 跨域资源配置(安全审计 M-8)。
 *
 * 此前项目未发布本配置,框架默认 allowed_origins=['*'] 对全部 api/* 开放跨域读——
 * 当前虽因不带凭据而不可直接利用,但属「默认全开」的地雷:任何未来新增的半私有
 * GET 端点都会被任意第三方站点读取。
 *
 * 默认不放开任何来源(storefront/sysadmin 与 API 同源,不需要 CORS);
 * 分站/独立前端域名需要跨域调 API 时,在 .env 配置 CORS_ALLOWED_ORIGINS
 * (完整 Origin,逗号分隔,例: https://shop.example.com,https://admin.example.com)。
 */
return [

    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    'allowed_origins' => array_values(array_filter(array_map('trim', explode(
        ',',
        (string) env('CORS_ALLOWED_ORIGINS', ''),
    )))),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => false,

];
