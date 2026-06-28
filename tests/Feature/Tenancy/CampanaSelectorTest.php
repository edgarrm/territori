<?php

namespace Tests\Feature\Tenancy;

use App\Models\Membership;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CampanaSelectorTest extends TestCase
{
    use RefreshDatabase;

    public function test_seleccionar_campana_con_membership_activa_setea_sesion(): void
    {
        $user = User::factory()->create();
        $tenant = Tenant::factory()->create();
        Membership::factory()->for($tenant)->for($user)->create(['activo' => true]);

        $response = $this->actingAs($user)->post(route('campanas.seleccionar.store'), [
            'tenant_id' => $tenant->id,
        ]);

        $response->assertRedirect(route('dashboard'));
        $this->assertSame($tenant->id, session('tenant_id'));
    }

    public function test_seleccionar_campana_sin_membership_activa_da_403(): void
    {
        $user = User::factory()->create();
        $tenant = Tenant::factory()->create();

        $response = $this->actingAs($user)->post(route('campanas.seleccionar.store'), [
            'tenant_id' => $tenant->id,
        ]);

        $response->assertForbidden();
        $this->assertNull(session('tenant_id'));
    }

    public function test_seleccionar_campana_con_membership_inactiva_da_403(): void
    {
        $user = User::factory()->create();
        $tenant = Tenant::factory()->create();
        Membership::factory()->for($tenant)->for($user)->create(['activo' => false]);

        $response = $this->actingAs($user)->post(route('campanas.seleccionar.store'), [
            'tenant_id' => $tenant->id,
        ]);

        $response->assertForbidden();
    }
}
