<?php

namespace Tests\Feature\Tenancy;

use App\Actions\Campana\RegistrarCampana;
use App\Models\Membership;
use App\Models\Municipio;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RegistrarCampanaTest extends TestCase
{
    use RefreshDatabase;

    public function test_accion_crea_tenant_y_admin_membership_del_creador(): void
    {
        $user = User::factory()->create();
        $municipio = Municipio::factory()->create();

        $tenant = app(RegistrarCampana::class)->handle($user, [
            'nombre' => 'Mi Campaña',
            'municipio_id' => $municipio->id,
        ]);

        $this->assertSame($municipio->id, $tenant->municipio_id);
        $this->assertDatabaseHas('memberships', [
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'rol' => 'admin',
            'activo' => true,
        ]);
        $this->assertNotNull($user->membershipEn($tenant)?->activado_en);
    }

    public function test_subdominio_duplicado_hace_rollback_completo(): void
    {
        $user = User::factory()->create();
        $municipio = Municipio::factory()->create();
        Tenant::create(['nombre' => 'Existente', 'municipio_id' => $municipio->id, 'subdominio' => 'repetido']);

        try {
            app(RegistrarCampana::class)->handle($user, [
                'nombre' => 'Nueva',
                'municipio_id' => $municipio->id,
                'subdominio' => 'repetido',
            ]);
            $this->fail('Se esperaba una QueryException por subdominio duplicado.');
        } catch (QueryException) {
            // esperado
        }

        $this->assertDatabaseMissing('tenants', ['nombre' => 'Nueva']);
        $this->assertSame(0, Membership::where('user_id', $user->id)->count());
    }

    public function test_admin_crea_campana_setea_sesion_y_redirige_al_dashboard(): void
    {
        [$user, $activa] = $this->adminConCampana();
        $municipio = Municipio::factory()->create();

        $response = $this->actingAs($user)->withSession(['tenant_id' => $activa->id])
            ->post(route('campanas.store'), [
                'nombre' => 'Campaña Web',
                'municipio_id' => $municipio->id,
            ]);

        $nueva = Tenant::where('nombre', 'Campaña Web')->firstOrFail();

        $response->assertRedirect(route('dashboard'));
        $this->assertSame($nueva->id, session('tenant_id'));
        $this->assertDatabaseHas('memberships', [
            'tenant_id' => $nueva->id,
            'user_id' => $user->id,
            'rol' => 'admin',
        ]);
    }

    public function test_store_valida_campos_requeridos(): void
    {
        [$user, $activa] = $this->adminConCampana();

        $response = $this->actingAs($user)->withSession(['tenant_id' => $activa->id])
            ->post(route('campanas.store'), []);

        $response->assertSessionHasErrors(['nombre', 'municipio_id']);
        $this->assertSame(1, Tenant::count());
    }

    public function test_no_admin_no_puede_crear_campana(): void
    {
        $user = User::factory()->create();
        $tenant = Tenant::factory()->create();
        Membership::factory()->for($tenant)->for($user)->coordinador()->create(['activo' => true]);
        $municipio = Municipio::factory()->create();

        $response = $this->actingAs($user)->withSession(['tenant_id' => $tenant->id])
            ->post(route('campanas.store'), [
                'nombre' => 'No debería',
                'municipio_id' => $municipio->id,
            ]);

        $response->assertForbidden();
        $this->assertDatabaseMissing('tenants', ['nombre' => 'No debería']);
    }

    /**
     * @return array{User, Tenant}
     */
    private function adminConCampana(): array
    {
        $user = User::factory()->create();
        $tenant = Tenant::factory()->create();
        Membership::factory()->for($tenant)->for($user)->admin()->create(['activo' => true]);

        return [$user, $tenant];
    }
}
