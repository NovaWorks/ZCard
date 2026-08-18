<?php

return [
    'disable' => env('CAPTCHA_DISABLE', false),
    'characters' => ['1', '2', '3', '4', '5', '6', '7', '8', '9', '0'],
    'fontsDirectory' => dirname(__DIR__) . '/assets/fonts',
    'bgsDirectory' => dirname(__DIR__) . '/assets/backgrounds',
    'default' => [
        'length' => 4,
        'width' => 120,
        'height' => 36,
        'quality' => 90,
        'math' => false,
        // 有效期 10 分钟:验证码答案存 Cache,check() 先判缓存再比对。
        // 60 秒太短,收银台填联系方式/密码/支付渠道/优惠券/靓号确认必然超时,
        // 缓存一过期 check 直接返回 false(用户输入正确也报错)。
        'expire' => 600,
        'encrypt' => false,
    ],
    // 下单(trade)场景:与 default 同参数。此前缺失导致 mews 用类默认值
    // (length=5/encrypt=true)生成,与校验逻辑不一致,验证码输入正确也报错。
    // bgImage=false 去除背景纹理,提高可读性,降低用户误输概率。
    'trade' => [
        'length' => 4,
        'width' => 120,
        'height' => 36,
        'quality' => 90,
        'math' => false,
        'expire' => 600,
        'encrypt' => false,
        'bgImage' => false,
        'bgColor' => '#ffffff',
        'lines' => 2,
        'fontColors' => ['#2563eb', '#111827', '#dc2626', '#16a34a'],
    ],
    // 注册(register)/登录(login)场景:参数必须与 trade 一致。
    // 缺失时 mews 的 configure() 不做任何事,直接沿用类默认值(length=5、width=120),
    // 5 个字符画在 120px 画布上会把最后一位裁出边界 —— 用户照着看不全的图输入,
    // 必然反复提示「验证码错误」。register 段同时服务前台注册页与找回密码页。
    'register' => [
        'length' => 4,
        'width' => 120,
        'height' => 36,
        'quality' => 90,
        'math' => false,
        'expire' => 600,
        'encrypt' => false,
        'bgImage' => false,
        'bgColor' => '#ffffff',
        'lines' => 2,
        'fontColors' => ['#2563eb', '#111827', '#dc2626', '#16a34a'],
    ],
    'login' => [
        'length' => 4,
        'width' => 120,
        'height' => 36,
        'quality' => 90,
        'math' => false,
        'expire' => 600,
        'encrypt' => false,
        'bgImage' => false,
        'bgColor' => '#ffffff',
        'lines' => 2,
        'fontColors' => ['#2563eb', '#111827', '#dc2626', '#16a34a'],
    ],

    'flat' => [
        'length' => 6,
        'fontColors' => ['#2c3e50', '#c0392b', '#16a085', '#c0392b', '#8e44ad', '#303f9f', '#f57c00', '#795548'],
        'width' => 345,
        'height' => 65,
        'math' => false,
        'quality' => 100,
        'lines' => 6,
        'bgImage' => true,
        'bgColor' => '#28faef',
        'contrast' => 0,
    ],
    'mini' => [
        'length' => 3,
        'width' => 60,
        'height' => 32,
    ],
    'inverse' => [
        'length' => 5,
        'width' => 120,
        'height' => 36,
        'quality' => 90,
        'sensitive' => true,
        'angle' => 12,
        'sharpen' => 10,
        'blur' => 2,
        'invert' => false,
        'contrast' => -5,
    ],
    'math' => [
        'length' => 9,
        'width' => 120,
        'height' => 36,
        'quality' => 90,
    ],
];
