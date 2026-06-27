<?php

namespace Tests\Feature\Brigadistas;

use App\Models\Membership;
use App\Models\Municipio;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class GestionBrigadistasTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{Tenant, User, Municipio}
     */
    private function tenantConCoordinador(array $tenantAttrs = []): array
    {
        $municipio = Municipio::factory()->create();
        $tenant = Tenant::factory()->create(array_merge(['municipio_id' => $municipio->id], $tenantAttrs));
        $user = User::factory()->create();
        Membership::factory()->coordinador()->create(['tenant_id' => $tenant->id, 'user_id' => $user->id]);

        return [$tenant, $user, $municipio];
    }

    private function actuandoEn(User $user, Tenant $tenant): static
    {
        return $this->actingAs($user)->withSession(['tenant_id' => $tenant->id]);
    }

    public function test_index_lista_solo_brigadistas_del_tenant(): void
    {
        [$tenant, $coord] = $this->tenantConCoordinador();
        $brigadista = Membership::factory()->brigadista()->create(['tenant_id' => $tenant->id]);

        // Ruido: coordinador (ya creado), brigadista de otro tenant.
        $otroTenant = Tenant::factory()->create();
        Membership::factory()->brigadista()->create(['tenant_id' => $otroTenant->id]);

        $this->actuandoEn($coord, $tenant)->get('/brigadistas')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Brigadistas')
                ->has('brigadistas', 1)
                ->where('brigadistas.0.membership_id', $brigadista->id)
                ->where('facturacion.activos', 1)
            );
    }

    public function test_brigadista_no_accede_a_gestion(): void
    {
        [$tenant, , $municipio] = $this->tenantConCoordinador();
        $user = User::factory()->create();
        Membership::factory()->brigadista()->create(['tenant_id' => $tenant->id, 'user_id' => $user->id]);

        $this->actuandoEn($user, $tenant)->get('/brigadistas')->assertForbidden();

        $otro = Membership::factory()->brigadista()->create(['tenant_id' => $tenant->id]);
        $this->actuandoEn($user, $tenant)
            ->putJson("/api/brigadistas/{$otro->id}/zonas", ['seccion_ids' => []])
            ->assertForbidden();
    }

    public function test_alta_crea_o_reusa_user(): void
    {
        [$tenant, $coord] = $this->tenantConCoordinador();

        $this->actuandoEn($coord, $tenant)
            ->postJson('/brigadistas', ['email' => 'nuevo@x.com', 'name' => 'Nuevo', 'meta_diaria' => 10])
            ->assertCreated();

        $user = User::where('email', 'nuevo@x.com')->first();
        $this->assertNotNull($user);
        $this->assertDatabaseHas('memberships', [
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'rol' => 'brigadista',
            'meta_diaria' => 10,
        ]);
    }

    public function test_activar_al_tope_devuelve_422(): void
    {
        [$tenant, $coord] = $this->tenantConCoordinador(['limite_brigadistas' => 1]);
        Membership::factory()->brigadista()->create(['tenant_id' => $tenant->id, 'activo' => true]);
        $inactivo = Membership::factory()->brigadista()->create(['tenant_id' => $tenant->id, 'activo' => false]);

        $this->actuandoEn($coord, $tenant)
            ->putJson("/api/brigadistas/{$inactivo->id}/activo", ['activo' => true])
            ->assertStatus(422);

        $this->assertDatabaseHas('memberships', ['id' => $inactivo->id, 'activo' => false]);
        $this->assertSame(1, $tenant->fresh()->brigadistasActivosCount());
    }

    public function test_desactivar_libera_cupo(): void
    {
        [$tenant, $coord] = $this->tenantConCoordinador(['limite_brigadistas' => 1]);
        $activo = Membership::factory()->brigadista()->create(['tenant_id' => $tenant->id, 'activo' => true]);
        $inactivo = Membership::factory()->brigadista()->create(['tenant_id' => $tenant->id, 'activo' => false]);

        $this->actuandoEn($coord, $tenant)
            ->putJson("/api/brigadistas/{$activo->id}/activo", ['activo' => false])
            ->assertOk();
        $this->assertNotNull($activo->fresh()->desactivado_en);

        // Ahora hay cupo para activar al otro.
        $this->actuandoEn($coord, $tenant)
            ->putJson("/api/brigadistas/{$inactivo->id}/activo", ['activo' => true])
            ->assertOk();
        $this->assertTrue($inactivo->fresh()->activo);
    }

    public function test_membership_de_otro_tenant_devuelve_404(): void
    {
        [$tenant, $coord] = $this->tenantConCoordinador();
        $ajeno = Membership::factory()->brigadista()->create(['tenant_id' => Tenant::factory()->create()->id]);

        $this->actuandoEn($coord, $tenant)
            ->putJson("/api/brigadistas/{$ajeno->id}/activo", ['activo' => false])
            ->assertNotFound();
    }

    public function test_ratios_endpoint(): void
    {
        [$tenant, $coord] = $this->tenantConCoordinador();
        $brigadista = Membership::factory()->brigadista()->create(['tenant_id' => $tenant->id, 'meta_diaria' => 5]);

        $this->actuandoEn($coord, $tenant)
            ->getJson("/api/brigadistas/{$brigadista->id}/ratios")
            ->assertOk()
            ->assertJsonStructure(['capturas_dia', 'capturas_total', 'pct_completos', 'avance_meta', 'secciones_asignadas']);
    }
}
