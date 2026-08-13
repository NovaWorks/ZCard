<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Crypt;

class SupplySource extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'name', 'driver', 'base_url', 'credentials', 'status', 'settings',
        'last_synced_at', 'last_error', 'balance_cache', 'sort',
    ];

    protected function casts(): array
    {
        return [
            'settings' => 'array',
            'last_synced_at' => 'datetime',
            'balance_cache' => 'integer',
        ];
    }

    /**
     * 上游商品前台链接(订单详情贴链接用)。
     * 优先取货源设置 product_url_template(模板支持 {base}/{code});
     * 未配置模板时使用同步阶段由驱动确认并保存的真实链接。
     *
     * 不再按驱动猜测路径。acg-faka 的对接 CODE 不是公开商品 ID，且不同版本/主题的
     * 分享链接可能是 /item/{id} 或 ?cid={categoryId}&mid={id}。
     */
    public function productUrlFor(?string $code, ?string $syncedUrl = null): ?string
    {
        $code = (string) ($code ?? '');
        $template = (string) ($this->settings['product_url_template'] ?? '');
        if ($template !== '') {
            return str_replace(
                ['{base}', '{code}'],
                [rtrim((string) $this->base_url, '/'), urlencode($code)],
                $template
            );
        }

        return $syncedUrl ?: null;
    }

    /**
     * credentials 手动加解密(容错):原用 encrypted:array cast,但 APP_KEY 变更后
     * 旧记录解密会抛 DecryptException 导致整个列表接口 500。改为手动 accessor,
     * 解密失败时返回空数组(该货源需重新配置凭证),不影响其他货源和列表加载。
     */
    private ?array $decodedCredentials = null;

    private bool $credentialsDecoded = false;

    public function getCredentialsAttribute($value): ?array
    {
        if ($this->credentialsDecoded) {
            return $this->decodedCredentials;
        }
        $this->credentialsDecoded = true;
        if (empty($value)) {
            return $this->decodedCredentials = [];
        }
        try {
            $decrypted = Crypt::decryptString($value);
            $arr = json_decode($decrypted, true);
            $this->decodedCredentials = is_array($arr) ? $arr : [];
        } catch (\Throwable) {
            // 旧密钥加密的记录无法解密 → 视为空凭证(需重新配置)
            $this->decodedCredentials = [];
        }

        return $this->decodedCredentials;
    }

    public function setCredentialsAttribute($value): void
    {
        $this->attributes['credentials'] = is_array($value)
            ? Crypt::encryptString(json_encode($value))
            : $value;
        // 失效内存缓存,避免 update 后读到的还是旧解密值
        $this->credentialsDecoded = false;
        $this->decodedCredentials = null;
    }

    /** credentials 是否因密钥变更而无法解密(用于提示用户重新配置) */
    public function credentialsCorrupted(): bool
    {
        $raw = $this->getRawOriginal('credentials');
        if (empty($raw)) {
            return false;
        }

        return empty($this->credentials);
    }

    public const DRIVER_DUJIAO_NEXT = 'dujiao_next';

    public const DRIVER_ACG_FAKA = 'acg_faka';

    public const DRIVER_ZCARD = 'zcard';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_DISABLED = 'disabled';

    public function products(): HasMany
    {
        return $this->hasMany(Product::class, 'upstream_source_id');
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }
}
