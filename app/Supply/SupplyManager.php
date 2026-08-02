<?php

namespace App\Supply;

use App\Models\SupplySource;
use App\Supply\Contracts\SupplyDriver;
use App\Supply\Drivers\AcgFakaDriver;
use App\Supply\Drivers\DujiaoNextDriver;
use App\Supply\Drivers\ZCardDriver;
use InvalidArgumentException;

/**
 * 货源驱动工厂(spec §3.1)
 * 按 SupplySource.driver 返回对应驱动实例。
 */
class SupplyManager
{
    /** driver 标识 → 驱动类 */
    public const DRIVERS = [
        SupplySource::DRIVER_DUJIAO_NEXT => DujiaoNextDriver::class,
        SupplySource::DRIVER_ACG_FAKA => AcgFakaDriver::class,
        SupplySource::DRIVER_ZCARD => ZCardDriver::class,
    ];

    public function driver(SupplySource $source): SupplyDriver
    {
        $class = self::DRIVERS[$source->driver] ?? null;
        if (! $class) {
            throw new InvalidArgumentException("未知货源驱动: {$source->driver}");
        }

        return new $class($source);
    }

    /** 所有可用驱动的 info + configSchema(供后台表单渲染) */
    public static function allDriversMeta(): array
    {
        $meta = [];
        foreach (self::DRIVERS as $key => $class) {
            $meta[] = [
                'driver' => $key,
                'name' => $class::info()['name'] ?? $key,
                'icon' => $class::info()['icon'] ?? null,
                'config_schema' => $class::configSchema(),
            ];
        }

        return $meta;
    }
}
