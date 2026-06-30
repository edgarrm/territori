<?php

namespace Tests\Feature\Privacidad;

use App\Models\AvisoPrivacidad;
use App\Models\Elector;
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
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class BandejaArcoTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{0: Tenant, 1: User, 2: Membership, 3: Seccion, 4: AvisoPrivacidad}
     */
    private function setupCampana(string $rol = 'coordinador'): array
    {
        (new CartografiaSeeder)->run(base_path('tests/Fixtures/cartografia/99-test'));
        $municipio = Municipio::query()->where('clave', 12)->first();
        $tenant = Tenant::factory()->create(['municipio_id' => $municipio->id]);
        $user = User::factory()->create();
        $membership = Membership::create(['tenant_id' => $tenant->id, 'user_id' => $user->id, 'rol' => $rol]);
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

    public function test_coordinador_ve_solicitudes_pendientes_por_defecto(): void
    {
        [$tenant, $user] = $this->setupCampana('coordinador');
        SolicitudArco::factory()->create(['tenant_id' => $tenant->id, 'tipo' => 'acceso', 'estado' => 'pendiente']);
        SolicitudArco::factory()->create(['tenant_id' => $tenant->id, 'tipo' => 'oposicion', 'estado' => 'atendida', 'atendido_en' => now()]);

        $this->actingAs($user)->withSession(['tenant_id' => $tenant->id])
            ->get('/solicitudes-arco')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('SolicitudesArco')
                ->where('estado', 'pendiente')
                ->has('solicitudes', 1)
                ->where('solicitudes.0.tipo', 'acceso')
            );
    }

    public function test_filtro_muestra_atendidas(): void
    {
        [$tenant, $user] = $this->setupCampana('coordinador');
        SolicitudArco::factory()->create(['tenant_id' => $tenant->id, 'tipo' => 'acceso', 'estado' => 'pendiente']);
        SolicitudArco::factory()->create(['tenant_id' => $tenant->id, 'tipo' => 'oposicion', 'estado' => 'atendida', 'atendido_en' => now()]);

        $this->actingAs($user)->withSession(['tenant_id' => $tenant->id])
            ->get('/solicitudes-arco?estado=atendida')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('SolicitudesArco')
                ->where('estado', 'atendida')
                ->has('solicitudes', 1)
                ->where('solicitudes.0.tipo', 'oposicion')
            );
    }

    public function test_brigadista_no_puede_ver_la_bandeja(): void
    {
        [$tenant] = $this->setupCampana('brigadista');
        $brig = User::query()->whereHas('memberships', fn ($q) => $q->where('tenant_id', $tenant->id))->first();

        $this->actingAs($brig)->withSession(['tenant_id' => $tenant->id])
            ->get('/solicitudes-arco')->assertForbidden();
    }

    public function test_atender_oposicion_marca_atendida_sin_borrar_elector(): void
    {
        [$tenant, $user, $m, $s, $a] = $this->setupCampana('coordinador');
        $e = $this->elector($tenant, $m, $s, $a, '5511112222');
        $sol = SolicitudArco::factory()->create(['tenant_id' => $tenant->id, 'elector_id' => $e->id, 'tipo' => 'oposicion', 'estado' => 'pendiente']);

        // Simula un request fresco: el contexto lo debe fijar el middleware, no
        // un residuo del setup (si no, un binding mal hecho pasaría en falso).
        TenantContext::clear();
        $this->actingAs($user)->withSession(['tenant_id' => $tenant->id])
            ->putJson("/api/solicitudes-arco/{$sol->id}/atendido")->assertOk();

        TenantContext::set($tenant);
        $sol->refresh();
        $this->assertSame('atendida', $sol->estado);
        $this->assertNotNull($sol->atendido_en);
        $this->assertNull(Elector::query()->find($e->id)->deleted_at);
    }

    public function test_atender_cancelacion_da_de_baja_y_no_duplica_solicitud(): void
    {
        [$tenant, $user, $m, $s, $a] = $this->setupCampana('coordinador');
        $e = $this->elector($tenant, $m, $s, $a, '5511112222');
        $sol = SolicitudArco::factory()->create(['tenant_id' => $tenant->id, 'elector_id' => $e->id, 'tipo' => 'cancelacion', 'estado' => 'pendiente']);

        TenantContext::clear();
        $this->actingAs($user)->withSession(['tenant_id' => $tenant->id])
            ->putJson("/api/solicitudes-arco/{$sol->id}/atendido")->assertOk();

        TenantContext::set($tenant);
        $trashed = Elector::withTrashed()->find($e->id);
        $this->assertNotNull($trashed->deleted_at);
        $this->assertSame('', $trashed->nombre);
        // Una sola solicitud para el elector: la misma, ahora atendida (sin duplicar).
        $this->assertSame(1, SolicitudArco::query()->where('elector_id', $e->id)->count());
        $sol->refresh();
        $this->assertSame('atendida', $sol->estado);
        $this->assertNotNull($sol->atendido_en);
    }

    public function test_brigadista_no_puede_atender(): void
    {
        [$tenant] = $this->setupCampana('brigadista');
        $brig = User::query()->whereHas('memberships', fn ($q) => $q->where('tenant_id', $tenant->id))->first();
        $sol = SolicitudArco::factory()->create(['tenant_id' => $tenant->id, 'tipo' => 'acceso', 'estado' => 'pendiente']);

        $this->actingAs($brig)->withSession(['tenant_id' => $tenant->id])
            ->putJson("/api/solicitudes-arco/{$sol->id}/atendido")->assertForbidden();

        TenantContext::set($tenant);
        $this->assertSame('pendiente', $sol->refresh()->estado);
    }

    public function test_registrar_rectificacion_persiste(): void
    {
        // 'rectificacion' tiene 13 caracteres: la columna tipo debe poder guardarlo.
        [$tenant, $user, $m, $s, $a] = $this->setupCampana('brigadista');
        $brig = User::query()->whereHas('memberships', fn ($q) => $q->where('tenant_id', $tenant->id))->first();
        $e = $this->elector($tenant, $m, $s, $a, '5511112222');

        $this->actingAs($brig)->withSession(['tenant_id' => $tenant->id])
            ->postJson('/api/solicitudes-arco', ['tipo' => 'rectificacion', 'elector_id' => $e->id])
            ->assertCreated();

        TenantContext::set($tenant);
        $this->assertSame('rectificacion', SolicitudArco::query()->where('elector_id', $e->id)->value('tipo'));
    }

    public function test_no_puede_atender_solicitud_de_otro_tenant(): void
    {
        [$tenant, $user] = $this->setupCampana('coordinador');
        $otro = Tenant::factory()->create();
        $solOtro = SolicitudArco::factory()->create(['tenant_id' => $otro->id, 'tipo' => 'acceso', 'estado' => 'pendiente']);

        $this->actingAs($user)->withSession(['tenant_id' => $tenant->id])
            ->putJson("/api/solicitudes-arco/{$solOtro->id}/atendido")->assertNotFound();
    }
}
