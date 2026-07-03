<?php

namespace Tests\Feature\Registros;

use App\Models\Elector;
use App\Models\Interaccion;
use App\Models\Membership;
use App\Models\Municipio;
use App\Models\Seccion;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Autorización a nivel de objeto: un brigadista solo accede (ver/editar) a los
 * electores y timelines de sus zonas asignadas o los que él capturó. Gestión ve
 * todos. Complementa la restricción de captura de RestriccionZonaTest, cubriendo
 * los endpoints que resuelven un elector por ID.
 */
class AccesoElectorPorZonaTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Municipio $municipio;

    private User $user;

    private Membership $brigadista;

    private Seccion $zonaAsignada;

    private Seccion $zonaAjena;

    protected function setUp(): void
    {
        parent::setUp();

        $this->municipio = Municipio::factory()->create();
        $this->tenant = Tenant::factory()->create(['municipio_id' => $this->municipio->id]);
        $this->user = User::factory()->create();
        $this->brigadista = Membership::create([
            'tenant_id' => $this->tenant->id,
            'user_id' => $this->user->id,
            'rol' => 'brigadista',
        ]);

        $this->zonaAsignada = Seccion::factory()->create(['municipio_id' => $this->municipio->id]);
        $this->zonaAjena = Seccion::factory()->create(['municipio_id' => $this->municipio->id]);
        $this->brigadista->secciones()->attach($this->zonaAsignada->id, ['tenant_id' => $this->tenant->id]);
    }

    private function elector(Seccion $seccion, ?Membership $capturadoPor = null): Elector
    {
        TenantContext::set($this->tenant);

        return Elector::factory()->create([
            'tenant_id' => $this->tenant->id,
            'seccion_id' => $seccion->id,
            'membership_id' => ($capturadoPor ?? $this->otroBrigadista())->id,
        ]);
    }

    private function otroBrigadista(): Membership
    {
        return Membership::create([
            'tenant_id' => $this->tenant->id,
            'user_id' => User::factory()->create()->id,
            'rol' => 'brigadista',
        ]);
    }

    private function sesion(): self
    {
        $this->actingAs($this->user)->withSession(['tenant_id' => $this->tenant->id]);

        return $this;
    }

    public function test_brigadista_no_ve_elector_fuera_de_su_zona(): void
    {
        $ajeno = $this->elector($this->zonaAjena);

        $this->sesion()->getJson("/api/electores/{$ajeno->id}")->assertForbidden();
        $this->sesion()->get("/electores/{$ajeno->id}")->assertForbidden();
        $this->sesion()->putJson("/api/electores/{$ajeno->id}", ['nombre' => 'Hackeado'])->assertForbidden();

        TenantContext::set($this->tenant);
        $this->assertNotSame('Hackeado', $ajeno->fresh()->nombre);
    }

    public function test_brigadista_no_accede_interacciones_fuera_de_su_zona(): void
    {
        $ajeno = $this->elector($this->zonaAjena);
        $interaccion = Interaccion::factory()->create([
            'tenant_id' => $this->tenant->id,
            'elector_id' => $ajeno->id,
            'membership_id' => $this->otroBrigadista()->id,
        ]);

        $this->sesion()->getJson("/api/electores/{$ajeno->id}/interacciones")->assertForbidden();
        $this->sesion()->postJson("/api/electores/{$ajeno->id}/interacciones", ['tipo' => 'llamada'])->assertForbidden();
        $this->sesion()->putJson("/api/interacciones/{$interaccion->id}/atendido")->assertForbidden();

        TenantContext::set($this->tenant);
        $this->assertNull($interaccion->fresh()->atendido_en);
    }

    public function test_brigadista_accede_a_elector_de_su_zona(): void
    {
        $propio = $this->elector($this->zonaAsignada);

        $this->sesion()->getJson("/api/electores/{$propio->id}")->assertOk();
        $this->sesion()->putJson("/api/electores/{$propio->id}", ['nombre' => 'Editado OK'])->assertOk();

        TenantContext::set($this->tenant);
        $this->assertSame('Editado OK', $propio->fresh()->nombre);
    }

    public function test_brigadista_accede_a_elector_que_capturo_aunque_no_sea_su_zona(): void
    {
        // Capturado por él en una sección que hoy NO tiene asignada.
        $capturado = $this->elector($this->zonaAjena, capturadoPor: $this->brigadista);

        $this->sesion()->getJson("/api/electores/{$capturado->id}")->assertOk();
    }

    public function test_coordinador_accede_a_cualquier_elector(): void
    {
        $coordUser = User::factory()->create();
        Membership::create(['tenant_id' => $this->tenant->id, 'user_id' => $coordUser->id, 'rol' => 'coordinador']);

        $ajeno = $this->elector($this->zonaAjena);

        $this->actingAs($coordUser)->withSession(['tenant_id' => $this->tenant->id])
            ->getJson("/api/electores/{$ajeno->id}")
            ->assertOk();
    }
}
