<?php

namespace App\Support;

/**
 * CSV 公式注入防护（OWASP CSV Injection）。
 *
 * 以 = + - @ \t \r 开头的单元格会被 Excel/WPS 当作公式或 DDE 命令执行，
 * 而这些字符可能来自用户可控字段（订单联系方式、卡密明文、备注、券码等）。
 * 所有导出 CSV 的单元格都必须先经过 CsvSafe::cell() 处理。
 */
class CsvSafe
{
    /**
     * 转义单个单元格：危险前缀加单引号前缀，其余原样返回。
     */
    public static function cell(mixed $value): string
    {
        $value = (string) $value;

        if ($value !== '' && strpbrk($value[0], "=+-@\t\r") !== false) {
            return "'".$value;
        }

        return $value;
    }

    /**
     * 批量转义一行。
     *
     * @param  array<int, mixed>  $row
     * @return array<int, string>
     */
    public static function row(array $row): array
    {
        return array_map([self::class, 'cell'], $row);
    }
}
