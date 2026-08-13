<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class SupplyDriversEndpointTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_load_all_supply_driver_metadata(): void
    {
        foreach (['super_admin', 'merchant', 'user'] as $role) {
            Role::firstOrCreate(['name' => $role]);
        }
        $user = User::factory()->create();
        $user->assignRole('super_admin');

        $response = $this->withToken($user->createToken('test')->plainTextToken)
            ->getJson('/api/admin/supply-sources/drivers');

        $response->assertOk()
            ->assertJsonCount(3, 'drivers')
            ->assertJsonPath('drivers.0.config_schema.base_url.type', 'url');
    }
}
