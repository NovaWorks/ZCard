<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class MigrationPortabilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_money_unit_migrations_use_integer_columns(): void
    {
        $integerTypes = ['bigint', 'integer'];

        $this->assertContains(Schema::getColumnType('cards', 'draft_premium'), $integerTypes);
        $this->assertContains(Schema::getColumnType('cards', 'draft_cost'), $integerTypes);
        $this->assertContains(Schema::getColumnType('user_groups', 'min_recharge'), $integerTypes);
        $this->assertContains(Schema::getColumnType('user_groups', 'min_consumption'), $integerTypes);
    }
}
