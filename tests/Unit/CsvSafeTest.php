<?php

namespace Tests\Unit;

use App\Support\CsvSafe;
use PHPUnit\Framework\TestCase;

class CsvSafeTest extends TestCase
{
    public function test_formula_prefixes_are_escaped(): void
    {
        foreach (['=SUM(A1)', '+1', '-1', '@cmd', "\tX", "\rX"] as $value) {
            $this->assertSame("'".$value, CsvSafe::cell($value));
        }
    }

    public function test_plain_values_are_untouched(): void
    {
        $this->assertSame('contact@example.com', CsvSafe::cell('contact@example.com'));
        $this->assertSame('正常备注', CsvSafe::cell('正常备注'));
        $this->assertSame('', CsvSafe::cell(''));
        $this->assertSame('0', CsvSafe::cell(0));
        $this->assertSame('123', CsvSafe::cell(123));
        $this->assertSame('', CsvSafe::cell(null));
    }

    public function test_row_escapes_every_cell(): void
    {
        $this->assertSame(
            ["'=SUM(A1)", "'-1", 'ok'],
            CsvSafe::row(['=SUM(A1)', '-1', 'ok']),
        );
    }
}
