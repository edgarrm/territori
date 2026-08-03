<?php

namespace Tests\Feature\Privacidad;

use App\Models\AvisoPrivacidad;
use App\Models\Elector;
use App\Models\Interaccion;
use App\Models\Membership;
use App\Models\Municipio;
use App\Models\Seccion;
use App\Models\SolicitudArco;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Telefono;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\CartografiaSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * P9/P10: hasta P4 los derechos de Acceso y Oposición no tenían efecto técnico
 * —marcar la solicitud "atendida" era un acuse vacío— y la tabla que existe
 * para dar trazabilidad no registraba quién atendió ni cuándo vencía el plazo.
 */
class DerechosArcoTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{0: Tenant, 1: User, 2: Membership, 3: Seccion, 4: AvisoPrivacidad}
     */
    private function setupCampana(): array
    {
        (new CartografiaSeeder)->run(base_path('tests/Fixtures/cartografia/99-test'));
        $municipio = Municipio::query()->where('clave', 12)->first();
        $tenant = Tenant::factory()->create(['municipio_id' => $municipio->id]);
        $user = User::factory()->withTwoFactor()->create();
        $membership = Membership::create(['tenant_id' => $tenant->id, 'user_id' => $user->id, 'rol' => 'coordinador']);
        TenantContext::set($tenant);
        $seccion = Seccion::query()->where('municipio_id', $municipio->id)->where('numero', 1)->first();
        $aviso = AvisoPrivacidad::factory()->create(['tenant_id' => $tenant->id]);

        return [$tenant, $user, $membership, $seccion, $aviso];
    }

    private function elector(Tenant $t, Membership $m, Seccion $s, AvisoPrivacidad $a, string $tel): Elector
    {
        return Elector::factory()->create([
            'tenant_id' => $t->id, 'seccion_id' => $s->id, 'membership_id' => $m->id,
            'aviso_privacidad_id' => $a->id, 'telefono' => $tel, 'telefono_hash' => Telefono::hash($tel),
        ]);
    }

    // --- Oposición: efecto técnico -----------------------------------------

    public function test_atender_oposicion_marca_no_contactar(): void
    {
        [$tenant, $user, $m, $s, $a] = $this->setupCampana();
        $e = $this->elector($tenant, $m, $s, $a, '5511112222');
        $sol = SolicitudArco::factory()->create([
            'tenant_id' => $tenant->id, 'elector_id' => $e->id,
            'tipo' => 'oposicion', 'estado' => 'pendiente',
        ]);

        TenantContext::clear();
        $this->actingAs($user)->withSession(['tenant_id' => $tenant->id])
            ->putJson("/api/solicitudes-arco/{$sol->id}/atendido")->assertOk();

        TenantContext::set($tenant);
        $this->assertTrue(Elector::query()->find($e->id)->no_contactar);
    }

    public function test_atender_acceso_no_marca_no_contactar(): void
    {
        [$tenant, $user, $m, $s, $a] = $this->setupCampana();
        $e = $this->elector($tenant, $m, $s, $a, '5511112222');
        $sol = SolicitudArco::factory()->create([
            'tenant_id' => $tenant->id, 'elector_id' => $e->id,
            'tipo' => 'acceso', 'estado' => 'pendiente',
        ]);

        TenantContext::clear();
        $this->actingAs($user)->withSession(['tenant_id' => $tenant->id])
            ->putJson("/api/solicitudes-arco/{$sol->id}/atendido")->assertOk();

        TenantContext::set($tenant);
        $this->assertFalse(Elector::query()->find($e->id)->no_contactar);
    }

    public function test_no_contactar_saca_al_elector_de_la_agenda(): void
    {
        [$tenant, $user, $m, $s, $a] = $this->setupCampana();
        $opuesto = $this->elector($tenant, $m, $s, $a, '5511112222');
        $normal = $this->elector($tenant, $m, $s, $a, '5533334444');
        $opuesto->update(['no_contactar' => true]);

        foreach ([$opuesto, $normal] as $elector) {
            Interaccion::factory()->pendienteHoy()->create([
                'tenant_id' => $tenant->id, 'elector_id' => $elector->id, 'membership_id' => $m->id,
            ]);
        }

        $response = $this->actingAs($user)->withSession(['tenant_id' => $tenant->id])
            ->getJson('/api/agenda');

        $response->assertOk();
        $ids = array_column($response->json('data'), 'elector');
        $this->assertSame([$normal->id], array_column($ids, 'id'));
    }

    public function test_no_contactar_saca_al_elector_del_export(): void
    {
        [$tenant, $user, $m, $s, $a] = $this->setupCampana();
        $opuesto = $this->elector($tenant, $m, $s, $a, '5511112222');
        $normal = $this->elector($tenant, $m, $s, $a, '5533334444');
        $opuesto->update(['no_contactar' => true, 'nombre' => 'Opuesto Opositor']);
        $normal->update(['nombre' => 'Normal Contactable']);

        $csv = $this->actingAs($user)
            ->withSession(['tenant_id' => $tenant->id, 'auth.password_confirmed_at' => time()])
            ->get('/api/export/electores.csv')
            ->streamedContent();

        $this->assertStringContainsString('Normal Contactable', $csv);
        $this->assertStringNotContainsString('Opuesto Opositor', $csv);
    }

    // --- Acceso: reporte para el titular -----------------------------------

    public function test_reporte_de_acceso_entrega_todo_lo_que_se_guarda(): void
    {
        [$tenant, $user, $m, $s, $a] = $this->setupCampana();
        $e = $this->elector($tenant, $m, $s, $a, '5511112222');
        $e->forceFill([
            'nombre' => 'Ana López',
            'email' => 'ana@correo.com',
            'domicilio' => 'Calle Falsa 123',
            'observaciones' => 'Interesada en becas',
        ])->save();
        Interaccion::factory()->create([
            'tenant_id' => $tenant->id, 'elector_id' => $e->id,
            'membership_id' => $m->id, 'nota' => 'Contestó a la primera',
        ]);

        $response = $this->actingAs($user)->withSession(['tenant_id' => $tenant->id])
            ->getJson("/api/electores/{$e->id}/reporte-acceso");

        $response->assertOk();
        $response->assertJsonPath('datos_personales.nombre', 'Ana López');
        $response->assertJsonPath('datos_personales.telefono', '5511112222');
        $response->assertJsonPath('datos_personales.email', 'ana@correo.com');
        $response->assertJsonPath('datos_personales.domicilio', 'Calle Falsa 123');
        $response->assertJsonPath('datos_personales.observaciones', 'Interesada en becas');
        $response->assertJsonPath('origen.seccion', $s->numero);
        $response->assertJsonPath('interacciones.0.nota', 'Contestó a la primera');
        $response->assertJsonPath('consentimiento.aviso_version', $a->version);
    }

    public function test_el_reporte_de_acceso_deja_traza_en_la_bitacora(): void
    {
        [$tenant, $user, $m, $s, $a] = $this->setupCampana();
        $e = $this->elector($tenant, $m, $s, $a, '5511112222');

        $this->actingAs($user)->withSession(['tenant_id' => $tenant->id])
            ->getJson("/api/electores/{$e->id}/reporte-acceso")->assertOk();

        $this->assertDatabaseHas('bitacora_accesos', [
            'tenant_id' => $tenant->id,
            'accion' => 'elector.reporte_acceso',
            'recurso_tipo' => 'elector',
            'recurso_id' => $e->id,
        ]);
    }

    public function test_brigadista_no_puede_generar_el_reporte_de_acceso(): void
    {
        [$tenant, , $m, $s, $a] = $this->setupCampana();
        $e = $this->elector($tenant, $m, $s, $a, '5511112222');

        $brigUser = User::factory()->create();
        Membership::create(['tenant_id' => $tenant->id, 'user_id' => $brigUser->id, 'rol' => 'brigadista']);

        $this->actingAs($brigUser)->withSession(['tenant_id' => $tenant->id])
            ->getJson("/api/electores/{$e->id}/reporte-acceso")->assertForbidden();
    }

    // --- Trazabilidad y plazo legal ----------------------------------------

    public function test_atender_registra_quien_atendio_y_los_timestamps(): void
    {
        [$tenant, $user, $m, $s, $a] = $this->setupCampana();
        $e = $this->elector($tenant, $m, $s, $a, '5511112222');
        $sol = SolicitudArco::factory()->create([
            'tenant_id' => $tenant->id, 'elector_id' => $e->id,
            'tipo' => 'rectificacion', 'estado' => 'pendiente',
        ]);

        TenantContext::clear();
        $this->actingAs($user)->withSession(['tenant_id' => $tenant->id])
            ->putJson("/api/solicitudes-arco/{$sol->id}/atendido")->assertOk();

        TenantContext::set($tenant);
        $sol->refresh();
        $this->assertSame($m->id, $sol->membership_id);
        $this->assertNotNull($sol->created_at);
        $this->assertNotNull($sol->updated_at);
    }

    public function test_la_cancelacion_tambien_registra_quien_atendio(): void
    {
        [$tenant, $user, $m, $s, $a] = $this->setupCampana();
        $e = $this->elector($tenant, $m, $s, $a, '5511112222');
        $sol = SolicitudArco::factory()->create([
            'tenant_id' => $tenant->id, 'elector_id' => $e->id,
            'tipo' => 'cancelacion', 'estado' => 'pendiente',
        ]);

        TenantContext::clear();
        $this->actingAs($user)->withSession(['tenant_id' => $tenant->id])
            ->putJson("/api/solicitudes-arco/{$sol->id}/atendido")->assertOk();

        TenantContext::set($tenant);
        $this->assertSame($m->id, $sol->refresh()->membership_id);
    }

    public function test_la_bandeja_expone_el_plazo_legal_de_veinte_dias_habiles(): void
    {
        [$tenant, $user] = $this->setupCampana();
        // 30 días naturales atrás es más de 20 hábiles: vencida.
        SolicitudArco::factory()->create([
            'tenant_id' => $tenant->id, 'tipo' => 'acceso', 'estado' => 'pendiente',
            'solicitado_en' => now()->subDays(45),
        ]);

        $this->actingAs($user)->withSession(['tenant_id' => $tenant->id])
            ->get('/solicitudes-arco')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('SolicitudesArco')
                ->where('solicitudes.0.vencida', true)
                ->etc()
            );
    }

    public function test_una_solicitud_recien_creada_no_esta_vencida(): void
    {
        [$tenant, $user] = $this->setupCampana();
        SolicitudArco::factory()->create([
            'tenant_id' => $tenant->id, 'tipo' => 'acceso', 'estado' => 'pendiente',
            'solicitado_en' => now(),
        ]);

        $this->actingAs($user)->withSession(['tenant_id' => $tenant->id])
            ->get('/solicitudes-arco')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('SolicitudesArco')
                ->where('solicitudes.0.vencida', false)
                ->where('solicitudes.0.dias_restantes', 20)
                ->etc()
            );
    }
}
