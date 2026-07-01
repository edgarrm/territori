<?php

namespace Tests\Feature\Privacidad;

use App\Models\AvisoPrivacidad;
use App\Models\CoberturaSeccion;
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
use Tests\TestCase;

class ArcoTest extends TestCase
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

    public function test_coordinador_cancela_con_baja_logica_scrub_y_recobertura(): void
    {
        [$tenant, $user, $m, $s, $a] = $this->setupCampana('coordinador');
        $e1 = $this->elector($tenant, $m, $s, $a, '5511112222');
        $this->elector($tenant, $m, $s, $a, '5533334444');

        $this->actingAs($user)->withSession(['tenant_id' => $tenant->id])
            ->deleteJson("/api/electores/{$e1->id}")->assertOk();

        TenantContext::set($tenant);
        // Baja lógica + scrub.
        $trashed = Elector::withTrashed()->find($e1->id);
        $this->assertNotNull($trashed->deleted_at);
        $this->assertSame('', $trashed->nombre);
        $this->assertNull($trashed->telefono_hash);
        // Solicitud ARCO registrada como cancelación atendida.
        $sol = SolicitudArco::query()->where('elector_id', $e1->id)->first();
        $this->assertSame('cancelacion', $sol->tipo);
        $this->assertSame('atendida', $sol->estado);
        // Cobertura recalculada: queda 1 vivo en la sección.
        $this->assertSame(1, Elector::query()->where('seccion_id', $s->id)->count());
        $this->assertSame(1, (int) CoberturaSeccion::query()->where('seccion_id', $s->id)->value('capturados'));
    }

    public function test_brigadista_no_puede_cancelar(): void
    {
        [$tenant, , $m, $s, $a] = $this->setupCampana('brigadista');
        $brigUser = User::query()->whereHas('memberships', fn ($q) => $q->where('tenant_id', $tenant->id))->first();
        $e1 = $this->elector($tenant, $m, $s, $a, '5511112222');

        $this->actingAs($brigUser)->withSession(['tenant_id' => $tenant->id])
            ->deleteJson("/api/electores/{$e1->id}")->assertForbidden();

        TenantContext::set($tenant);
        $this->assertNull(Elector::query()->find($e1->id)->deleted_at);
    }

    public function test_elector_cancelado_no_aparece(): void
    {
        [$tenant, $user, $m, $s, $a] = $this->setupCampana('coordinador');
        $e1 = $this->elector($tenant, $m, $s, $a, '5511112222');

        $this->actingAs($user)->withSession(['tenant_id' => $tenant->id])
            ->deleteJson("/api/electores/{$e1->id}")->assertOk();

        $this->actingAs($user)->withSession(['tenant_id' => $tenant->id])
            ->getJson("/api/electores/{$e1->id}")->assertNotFound();
    }

    public function test_recaptura_del_mismo_telefono_tras_cancelacion(): void
    {
        [$tenant, $user, $m, $s, $a] = $this->setupCampana('coordinador');
        $e1 = $this->elector($tenant, $m, $s, $a, '5511112222');

        $this->actingAs($user)->withSession(['tenant_id' => $tenant->id])
            ->deleteJson("/api/electores/{$e1->id}")->assertOk();

        // El mismo teléfono se puede volver a capturar (el dedup ve solo vivos).
        $this->actingAs($user)->withSession(['tenant_id' => $tenant->id])
            ->postJson('/api/electores', [
                'modo_captura' => 'enlace_seccional',
                'seccion_id' => $s->id,
                'nombre' => 'Recapturado',
                'telefono' => '5511112222',
                'consentimiento' => true,
                'aviso_privacidad_id' => $a->id,
            ])->assertCreated();
    }

    public function test_registrar_solicitud_arco_no_modifica_al_elector(): void
    {
        [$tenant, $user, $m, $s, $a] = $this->setupCampana('brigadista');
        $brigUser = User::query()->whereHas('memberships', fn ($q) => $q->where('tenant_id', $tenant->id))->first();
        $e1 = $this->elector($tenant, $m, $s, $a, '5511112222');

        $this->actingAs($brigUser)->withSession(['tenant_id' => $tenant->id])
            ->postJson('/api/solicitudes-arco', ['tipo' => 'acceso', 'elector_id' => $e1->id])
            ->assertCreated();

        TenantContext::set($tenant);
        $sol = SolicitudArco::query()->first();
        $this->assertSame('acceso', $sol->tipo);
        $this->assertSame('pendiente', $sol->estado);
        // El elector sigue intacto.
        $this->assertNull(Elector::query()->find($e1->id)->deleted_at);
    }
}
