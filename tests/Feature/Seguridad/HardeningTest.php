<?php

namespace Tests\Feature\Seguridad;

use App\Models\Membership;
use App\Models\Municipio;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HardeningTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{User, Tenant}
     */
    private function adminConCampana(): array
    {
        $user = User::factory()->create();
        $tenant = Tenant::factory()->create();
        Membership::create(['tenant_id' => $tenant->id, 'user_id' => $user->id, 'rol' => 'admin', 'activo' => true]);

        return [$user, $tenant];
    }

    public function test_rutas_de_dominio_aplican_rate_limit(): void
    {
        [$user, $tenant] = $this->adminConCampana();

        $response = $this->actingAs($user)->withSession(['tenant_id' => $tenant->id])
            ->get(route('dashboard'));

        $response->assertOk();
        $response->assertHeader('X-RateLimit-Limit', '120');
    }

    public function test_membership_inactiva_no_es_resuelta_por_membership_en(): void
    {
        $user = User::factory()->create();
        $tenant = Tenant::factory()->create();
        Membership::create(['tenant_id' => $tenant->id, 'user_id' => $user->id, 'rol' => 'brigadista', 'activo' => false]);

        $this->assertNull($user->membershipEn($tenant));
    }

    public function test_marca_color_no_hexadecimal_es_rechazado(): void
    {
        [$user, $tenant] = $this->adminConCampana();
        $municipio = Municipio::factory()->create();

        $response = $this->actingAs($user)->withSession(['tenant_id' => $tenant->id])
            ->post(route('campanas.store'), [
                'nombre' => 'Color inválido',
                'municipio_id' => $municipio->id,
                'marca_color' => 'rojo;}',
            ]);

        $response->assertSessionHasErrors('marca_color');
        $this->assertDatabaseMissing('tenants', ['nombre' => 'Color inválido']);
    }

    public function test_marca_color_hexadecimal_valido_se_acepta(): void
    {
        [$user, $tenant] = $this->adminConCampana();
        $municipio = Municipio::factory()->create();

        $response = $this->actingAs($user)->withSession(['tenant_id' => $tenant->id])
            ->post(route('campanas.store'), [
                'nombre' => 'Color válido',
                'municipio_id' => $municipio->id,
                'marca_color' => '#1d4ed8',
            ]);

        $response->assertRedirect(route('dashboard'));
        $this->assertDatabaseHas('tenants', ['nombre' => 'Color válido', 'marca_color' => '#1d4ed8']);
    }
}
