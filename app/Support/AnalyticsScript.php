<?php

namespace App\Support;

/**
 * 站点统计代码(Google Analytics 4 / 百度统计 等)的编译器（issue #39）。
 *
 * 背景:v1.12.55 的安全修复把 footer_analytics 移出公开配置并删除了前台注入逻辑，
 * 但后台字段与持久化仍在，导致「保存成功、前台无任何统计请求」的静默回归；
 * v1.12.90 的严格 CSP 又形成第二层阻断。
 *
 * 本类不恢复"任意脚本原样注入"，而是复用客服脚本已审计的编译链路
 * （见基类 {@see ThirdPartyScript}）：脚本经受信域名白名单校验后编译成同源 JS，
 * 由 /api/settings/analytics-script 下发；CSP 仅在启用统计时按白名单精确放宽。
 */
final class AnalyticsScript extends ThirdPartyScript
{
    /**
     * 默认受信统计域名(允许追加)。按「主域名 + 任意层级子域名」放行，因此
     * googletagmanager.com 覆盖 www.googletagmanager.com、
     * google-analytics.com 覆盖 region1.google-analytics.com 等采集端点。
     */
    public const DEFAULT_ALLOWED_HOSTS = [
        'googletagmanager.com',   // GA4 / GTM 脚本
        'google-analytics.com',   // GA4 采集(含 www / region1 等子域名)
        'analytics.google.com',   // GA4 部分采集与调试端点
        'hm.baidu.com',           // 百度统计
        'clarity.ms',             // Microsoft Clarity
    ];

    protected static function label(): string
    {
        return '统计代码';
    }
}
