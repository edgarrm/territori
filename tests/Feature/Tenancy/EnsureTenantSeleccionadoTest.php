<?php

namespace Tests\Feature\Tenancy;

use App\Models\Membership;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EnsureTenantSeleccionadoTest extends TestCase
{
    use RefreshDatabase;

    public function test_sin_campanas_redirige_a_sin_membership(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get(route('dashboard'));

        $response->assertRedirect(route('campanas.sin-membership'));
    }

    public function test_una_sola_campana_se_autoselecciona_y_reentra(): void
    {
        $user = User::factory()->create();
        $tenant = Tenant::factory()->create();
        Membership::factory()->for($tenant)->for($user)->create(['activo' => true]);

        $response = $this->actingAs($user)->get(route('dashboard'));

        $response->assertRedirect(route('dashboard'));
        $this->assertSame($tenant->id, session('tenant_id'));
    }

    public function test_varias_campanas_redirige_al_selector(): void
    {
        $user = User::factory()->create();
        $primera = Tenant::factory()->create();
        $segunda = Tenant::factory()->create();
        Membership::factory()->for($primera)->for($user)->create(['activo' => true]);
        Membership::factory()->for($segunda)->for($user)->create(['activo' => true]);

        $response = $this->actingAs($user)->get(route('dashboard'));

        $response->assertRedirect(route('campanas.seleccionar'));
    }

    public function test_campana_inactiva_no_cuenta_y_redirige_a_sin_membership(): void
    {
        $user = User::factory()->create();
        $tenant = Tenant::factory()->create();
        Membership::factory()->for($tenant)->for($user)->create(['activo' => false]);

        $response = $this->actingAs($user)->get(route('dashboard'));

        $response->assertRedirect(route('campanas.sin-membership'));
    }
}
