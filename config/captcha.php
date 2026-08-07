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
        'expire' => 60,
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
        'expire' => 60,
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
