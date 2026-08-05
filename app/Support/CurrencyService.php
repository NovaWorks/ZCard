<?php

namespace App\Support;

use App\Models\Currency;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * 货币换算/格式化服务(spec §3.1)。
 * 基础金额(分) × exchange_rate = 目标金额(分)。
 */
class CurrencyService
{
    public const CACHE_KEY = 'currencies:enabled';
    public const CACHE_TTL = 3600;

    /** 基础货币 code(来自 StorefrontConfig,默认 CNY) */
    public function getBaseCurrency(): string
    {
        return (string) (StorefrontConfig::get('base_currency') ?? 'CNY');
    }

    /** 启用货币集合(带缓存)。返回模型集合(调用方用 -> 访问属性)。 */
    public function getEnabledCurrencies(): Collection
    {
        // 注意:缓存「纯数组」而非 Eloquent 对象。database cache 存的是 PHP serialize
        // 的二进制(Eloquent 对象含 \0 字节),MySQL 存取会破坏数据,反序列化成
        // __PHP_Incomplete_Class → TypeError。数组序列化是纯 ASCII,任何 driver 都安全。
        $rows = Cache::remember(self::CACHE_KEY, self::CACHE_TTL, function () {
            // 基础货币始终包含(即使误关)
            return Currency::where('is_enabled', true)
                ->orWhere('is_base', true)
                ->orderBy('sort')
                ->get()
                ->toArray();
        });

        // 缓存里是纯数组,返回时 hydrate 成模型集合(无 DB 查询,casts 生效)
        return collect($rows)->map(fn (array $item) => Currency::hydrate([$item])->first());
    }

    public function getCurrency(string $code): ?Currency
    {
        return $this->getEnabledCurrencies()->firstWhere('code', strtoupper($code));
    }

    /**
     * 基础金额(分) → 目标货币金额(分) + 汇率。
     * 返回 ['amount'=>int, 'rate'=>string, 'currency'=>string]
     */
    public function convert(int $baseFen, string $toCurrency): array
    {
        $cur = $this->getCurrency($toCurrency);
        if (! $cur) {
            return ['amount' => $baseFen, 'rate' => '1', 'currency' => $this->getBaseCurrency()];
        }
        // 目标即基础货币:金额不变,汇率恒为 '1'(与存储精度无关)
        if ($cur->code === $this->getBaseCurrency()) {
            return ['amount' => $baseFen, 'rate' => '1', 'currency' => $cur->code];
        }
        // 分 → 元 × rate → 元 → 分(按 decimal_places 取整)
        $yuan = bcdiv((string) $baseFen, '100', 8);
        $convertedYuan = bcmul($yuan, (string) $cur->exchange_rate, 8);
        // 最小单位 = 元 × 10^decimal_places
        $minUnit = bcpow('10', (string) $cur->decimal_places);
        $amountMin = $this->bcRound(bcmul($convertedYuan, $minUnit, 8)); // 四舍五入到整数分
        return [
            'amount' => (int) $amountMin,
            'rate' => (string) $cur->exchange_rate,
            'currency' => $cur->code,
        ];
    }

    /** 格式化最小单位为带符号字符串,如 "¥12.50" */
    public function format(int $minUnit, string $currency): string
    {
        $cur = $this->getCurrency($currency);
        if (! $cur) {
            return (string) ($minUnit / 100);
        }
        $minUnit = (string) $minUnit;
        $divisor = bcpow('10', (string) $cur->decimal_places);
        $value = bcdiv($minUnit, $divisor, $cur->decimal_places);
        $negative = str_starts_with($value, '-');
        if ($negative) {
            $value = ltrim($value, '-'); // 去掉负号,稍后前置
        }
        $body = $cur->symbol_position === 'before'
            ? $cur->symbol . $value
            : $value . $cur->symbol;
        return ($negative ? '-' : '') . $body;
    }

    /** bcmath 四舍五入到整数( bankers-free, half-up ) */
    private function bcRound(string $value): string
    {
        if ($value[0] === '-') {
            // 负数: -1.5 → -2 (round half away from zero, consistent with PHP round() default for display)
            return bcsub($value, '0.5', 0);
        }
        return bcadd($value, '0.5', 0);
    }

    /** 清缓存(管理员改汇率后调用) */
    public function flushCache(): void
    {
        Cache::forget(self::CACHE_KEY);
    }
}
