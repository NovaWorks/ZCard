<?php

namespace App\Support;

/**
 * 客服组件安装代码(Chatwoot/Crisp 等)的编译器。
 *
 * 编译与 CSP 来源提取逻辑见基类 {@see ThirdPartyScript};本类只声明客服组件的
 * 默认受信域名。白名单可通过 StorefrontConfig 的 service_widget_allowed_hosts 追加。
 */
final class ServiceWidgetScript extends ThirdPartyScript
{
    /** 默认受信客服脚本域名(允许被覆盖)。Crisp 的实时通道(relay)/图片/存储是独立子域名,必须一并放行。 */
    public const DEFAULT_ALLOWED_HOSTS = [
        'app.chatwoot.com',
        'cdn.chatwoot.com',
        'client.crisp.chat',
        'settings.crisp.chat',
        'client.relay.crisp.chat',
        'image.crisp.chat',
        'storage.crisp.chat',
        'widget.crisp.chat',
    ];

    protected static function label(): string
    {
        return '客服脚本';
    }
}
