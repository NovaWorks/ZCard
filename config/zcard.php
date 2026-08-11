<?php

// ZCard 应用配置。功能开关（Open Core）预留，Phase 0 不消费，后续 Phase 3+ 启用。

return [
    // 模拟支付必须在 local 环境且显式开启；生产环境始终不可用。
    'allow_mock_payment' => (bool) env('ZCARD_ALLOW_MOCK_PAYMENT', false),

    // 功能开关：商业版功能默认 false（开源版）。
    'features' => [
        'multi_merchant' => env('ZCARD_MULTI_MERCHANT', false), // 多商户/多店（Phase 3）
        'distribution' => env('ZCARD_DISTRIBUTION', false),     // 三级分销（Phase 3）
        'sub_site' => env('ZCARD_SUB_SITE', false),             // 分站（Phase 3）
        // 货源对接总开关。运行时改读 StorefrontConfig::get('supply_enabled'),在 sysadmin 设置页配置;此处仅兜底
        'supply' => env('ZCARD_SUPPLY', false),
    ],

    // 卡密加密密钥（应用层 AES，spec §6.1 决策3）。32 字节 base64。
    'card_encryption_key' => env('CARD_ENCRYPTION_KEY'),

    // 卡密默认发放模式：status=保留/used，delete=物理删除（spec §6.1 决策12）
    'card_default_delivery_mode' => env('ZCARD_CARD_DELIVERY_MODE', 'status'),

    // 在线更新配置(Git-based,比 acg-faka 的 OTA zip 方案更安全)
    'update' => [
        'repo' => env('ZCARD_UPDATE_REPO', 'NovaWorks/ZCard'), // GitHub 仓库名
    ],

    /**
     * 货源对接配置(spec §8.5)
     * 注意: 运行时真理源已迁至 StorefrontConfig(supply_* 键),在 sysadmin「设置 → 货源对接」
     * 网页配置,改后立即生效。下方 env() 仅作安装前/首次部署的兜底默认值,运行时代码不再读它。
     * 数据库/Redis/邮件等基础设施配置仍在 .env(不进网页)。
     */
    'supply' => [
        'upstream_enabled' => env('ZCARD_SUPPLY_UPSTREAM', true),
        'supplier_enabled' => env('ZCARD_SUPPLY_SUPPLIER', true),
        'nonce_store' => env('ZCARD_SUPPLY_NONCE_STORE', 'cache'), // redis|cache|database
        'rate_limit' => env('ZCARD_SUPPLY_RATE_LIMIT', 60),        // 每分钟/账号
        'timestamp_skew' => env('ZCARD_SUPPLY_TS_SKEW', 300),      // 秒,签名时间窗口
    ],
];
