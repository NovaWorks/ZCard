<?php

// ZCard 应用配置。功能开关（Open Core）预留，Phase 0 不消费，后续 Phase 3+ 启用。

return [

    // 功能开关：商业版功能默认 false（开源版）。
    'features' => [
        'multi_merchant' => env('ZCARD_MULTI_MERCHANT', false), // 多商户/多店（Phase 3）
        'distribution' => env('ZCARD_DISTRIBUTION', false),     // 三级分销（Phase 3）
        'sub_site' => env('ZCARD_SUB_SITE', false),             // 分站（Phase 3）
    ],

    // 卡密加密密钥（应用层 AES，spec §6.1 决策3）。32 字节 base64。
    'card_encryption_key' => env('CARD_ENCRYPTION_KEY'),

    // 卡密默认发放模式：status=保留/used，delete=物理删除（spec §6.1 决策12）
    'card_default_delivery_mode' => env('ZCARD_CARD_DELIVERY_MODE', 'status'),
];
