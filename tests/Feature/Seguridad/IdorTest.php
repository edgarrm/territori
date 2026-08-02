<?php

namespace Tests\Feature\Seguridad;

use App\Models\Elector;
use App\Models\Evento;
use App\Models\Loteria;
use App\Models\Membership;
use App\Models\Municipio;
use App\Models\Seccion;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Autorización a nivel de objeto sobre entidades compartidas (lotería, evento):
 * un miembro sin propiedad ni zona asignada no debe poder enumerar a los
 * electores de otro. Cubre A1 y A2 de la auditoría de seguridad (P2).
 */
class IdorTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Municipio $municipio;

    protected function setUp(): void
    {
        parent::setUp();

        $this->municipio = Municipio::factory()->create();
        $this->tenant = Tenant::factory()->create(['municipio_id' => $this->municipio->id]);
    }

    private function miembro(string $rol): array
    {
        $user = User::factory()->create();
        $membership = Membership::create([
            'tenant_id' => $this->tenant->id,
            'user_id' => $user->id,
            'rol' => $rol,
        ]);

        return [$user, $membership];
    }

    private function sesion(User $user): self
    {
        $this->actingAs($user)->withSession(['tenant_id' => $this->tenant->id]);

        return $this;
    }

    public function test_anfitrion_no_ve_electores_de_loteria_ajena(): void
    {
        [, $encargado] = $this->miembro('anfitrion');
        [$intruso] = $this->miembro('anfitrion');

        TenantContext::set($this->tenant);
        $seccion = Seccion::factory()->create(['municipio_id' => $this->municipio->id]);
        $loteria = Loteria::factory()->create([
            'tenant_id' => $this->tenant->id,
            'membership_id' => $encargado->id,
            'creada_por_membership_id' => $encargado->id,
            'seccion_id' => $seccion->id,
        ]);
        Elector::factory()->create([
            'tenant_id' => $this->tenant->id,
            'seccion_id' => $seccion->id,
            'membership_id' => $encargado->id,
            'loteria_id' => $loteria->id,
            'modo_captura' => 'loteria',
            'nombre' => 'Contacto Ajeno',
        ]);

        $this->sesion($intruso)
            ->getJson("/api/loterias/{$loteria->id}/electores")
            ->assertForbidden();
    }

    public function test_anfitrion_encargado_si_ve_su_propia_loteria(): void
    {
        [$user, $encargado] = $this->miembro('anfitrion');

        TenantContext::set($this->tenant);
        $seccion = Seccion::factory()->create(['municipio_id' => $this->municipio->id]);
        $loteria = Loteria::factory()->create([
            'tenant_id' => $this->tenant->id,
            'membership_id' => $encargado->id,
            'creada_por_membership_id' => $encargado->id,
            'seccion_id' => $seccion->id,
        ]);

        $this->sesion($user)
            ->getJson("/api/loterias/{$loteria->id}/electores")
            ->assertOk();
    }

    public function test_brigadista_no_ve_asistentes_de_evento_fuera_de_su_zona(): void
    {
        [$user, $brigadista] = $this->miembro('brigadista');
        [, $otroBrigadista] = $this->miembro('brigadista');

        TenantContext::set($this->tenant);
        $zonaAsignada = Seccion::factory()->create(['municipio_id' => $this->municipio->id]);
        $zonaAjena = Seccion::factory()->create(['municipio_id' => $this->municipio->id]);
        $brigadista->secciones()->attach($zonaAsignada->id, ['tenant_id' => $this->tenant->id]);

        $evento = Evento::factory()->create([
            'tenant_id' => $this->tenant->id,
            'seccion_id' => $zonaAjena->id,
        ]);
        Elector::factory()->create([
            'tenant_id' => $this->tenant->id,
            'seccion_id' => $zonaAjena->id,
            'membership_id' => $otroBrigadista->id,
            'evento_id' => $evento->id,
            'modo_captura' => 'evento',
            'nombre' => 'Asistente Fuera De Zona',
        ]);

        $response = $this->sesion($user)->getJson("/api/eventos/{$evento->id}/asistentes");

        $response->assertOk();
        $response->assertJsonPath('total', 0);
    }

    public function test_brigadista_ve_asistentes_de_evento_en_su_zona(): void
    {
        [$user, $brigadista] = $this->miembro('brigadista');

        TenantContext::set($this->tenant);
        $zonaAsignada = Seccion::factory()->create(['municipio_id' => $this->municipio->id]);
        $brigadista->secciones()->attach($zonaAsignada->id, ['tenant_id' => $this->tenant->id]);

        $evento = Evento::factory()->create([
            'tenant_id' => $this->tenant->id,
            'seccion_id' => $zonaAsignada->id,
        ]);
        Elector::factory()->create([
            'tenant_id' => $this->tenant->id,
            'seccion_id' => $zonaAsignada->id,
            'membership_id' => $brigadista->id,
            'evento_id' => $evento->id,
            'modo_captura' => 'evento',
        ]);

        $response = $this->sesion($user)->getJson("/api/eventos/{$evento->id}/asistentes");

        $response->assertOk();
        $response->assertJsonPath('total', 1);
    }

    public function test_brigadista_no_puede_registrar_arco_de_elector_fuera_de_su_alcance(): void
    {
        [$user] = $this->miembro('brigadista');
        [, $otroBrigadista] = $this->miembro('brigadista');

        TenantContext::set($this->tenant);
        $zonaAjena = Seccion::factory()->create(['municipio_id' => $this->municipio->id]);
        $elector = Elector::factory()->create([
            'tenant_id' => $this->tenant->id,
            'seccion_id' => $zonaAjena->id,
            'membership_id' => $otroBrigadista->id,
        ]);

        $this->sesion($user)
            ->postJson('/api/solicitudes-arco', ['tipo' => 'acceso', 'elector_id' => $elector->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('elector_id');
    }

    public function test_coordinador_ve_asistentes_de_cualquier_zona(): void
    {
        [$user] = $this->miembro('coordinador');
        [, $brigadista] = $this->miembro('brigadista');

        TenantContext::set($this->tenant);
        $seccion = Seccion::factory()->create(['municipio_id' => $this->municipio->id]);
        $evento = Evento::factory()->create([
            'tenant_id' => $this->tenant->id,
            'seccion_id' => $seccion->id,
        ]);
        Elector::factory()->create([
            'tenant_id' => $this->tenant->id,
            'seccion_id' => $seccion->id,
            'membership_id' => $brigadista->id,
            'evento_id' => $evento->id,
            'modo_captura' => 'evento',
        ]);

        $this->sesion($user)
            ->getJson("/api/eventos/{$evento->id}/asistentes")
            ->assertOk()
            ->assertJsonPath('total', 1);
    }
}
