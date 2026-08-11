<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;

class PaymentChannel extends Model
{
    protected $fillable = [
        'merchant_id', 'name', 'code', 'driver', 'config',
        'fee', 'fee_type', 'fee_bearer', 'sort', 'enabled',
    ];

    /** 支付凭据不得因模型序列化意外进入接口响应。 */
    protected $hidden = ['config'];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
        ];
    }

    /**
     * 支付配置整体加密保存；读取兼容升级前的明文 JSON。
     * 外层 json_encode 使密文仍可写入历史 JSON 列。
     */
    protected function config(): Attribute
    {
        return Attribute::make(
            get: function ($value): array {
                if ($value === null || $value === '') {
                    return [];
                }

                $decoded = json_decode((string) $value, true);
                if (is_array($decoded)) {
                    return $decoded;
                }

                if (is_string($decoded)) {
                    try {
                        $plain = Crypt::decryptString($decoded);
                        $config = json_decode($plain, true);

                        return is_array($config) ? $config : [];
                    } catch (\Throwable) {
                        return [];
                    }
                }

                return [];
            },
            set: function ($value): ?string {
                if ($value === null) {
                    return null;
                }

                $plain = json_encode((array) $value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

                return json_encode(Crypt::encryptString($plain), JSON_UNESCAPED_SLASHES);
            },
        );
    }
}
