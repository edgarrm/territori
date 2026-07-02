<?php

namespace Tests\Feature\Interacciones;

use App\Models\AvisoPrivacidad;
use App\Models\Elector;
use App\Models\Interaccion;
use App\Models\Membership;
use App\Models\Municipio;
use App\Models\Seccion;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\CartografiaSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AgendaTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Municipio $municipio;

    private Seccion $seccion;

    private AvisoPrivacidad $aviso;

    protected function setUp(): void
    {
        parent::setUp();
        // La página de agenda es Inertia; sin build no hay manifest de Vite.
        $this->withoutVite();
        (new CartografiaSeeder)->run(base_path('tests/Fixtures/cartografia/99-test'));
        $this->municipio = Municipio::query()->where('clave', 12)->first();
        $this->tenant = Tenant::factory()->create(['municipio_id' => $this->municipio->id]);
        TenantContext::set($this->tenant);
        $this->seccion = Seccion::query()->where('municipio_id', $this->municipio->id)->where('numero', 1)->first();
        $this->aviso = AvisoPrivacidad::factory()->create(['tenant_id' => $this->tenant->id]);
    }

    private function miembro(string $rol): array
    {
        $user = User::factory()->create();
        $membership = Membership::create(['tenant_id' => $this->tenant->id, 'user_id' => $user->id, 'rol' => $rol]);

        return [$user, $membership];
    }

    private function elector(Membership $membership, ?Seccion $seccion = null, ?string $nombre = null): Elector
    {
        return Elector::factory()->create([
            'tenant_id' => $this->tenant->id,
            'seccion_id' => ($seccion ?? $this->seccion)->id,
            'membership_id' => $membership->id,
            'aviso_privacidad_id' => $this->aviso->id,
            ...($nombre !== null ? ['nombre' => $nombre] : []),
        ]);
    }

    public function test_seguimiento_vencido_aparece_y_al_marcar_atendido_desaparece(): void
    {
        [$user, $membership] = $this->miembro('brigadista');
        $elector = $this->elector($membership);
        $interaccion = Interaccion::factory()->pendienteHoy()->create([
            'tenant_id' => $this->tenant->id, 'elector_id' => $elector->id, 'membership_id' => $membership->id,
        ]);

        $antes = $this->actingAs($user)->withSession(['tenant_id' => $this->tenant->id])->getJson('/api/agenda');
        $antes->assertOk();
        $antes->assertJsonCount(1, 'data');

        $this->actingAs($user)->withSession(['tenant_id' => $this->tenant->id])
            ->putJson("/api/interacciones/{$interaccion->id}/atendido")->assertOk();

        $despues = $this->actingAs($user)->withSession(['tenant_id' => $this->tenant->id])->getJson('/api/agenda');
        $despues->assertJsonCount(0, 'data');
    }

    public function test_seguimiento_futuro_no_aparece(): void
    {
        [$user, $membership] = $this->miembro('brigadista');
        $elector = $this->elector($membership);
        Interaccion::factory()->create([
            'tenant_id' => $this->tenant->id, 'elector_id' => $elector->id, 'membership_id' => $membership->id,
            'proximo_seguimiento' => now()->addWeek()->toDateString(),
        ]);

        $response = $this->actingAs($user)->withSession(['tenant_id' => $this->tenant->id])->getJson('/api/agenda');
        $response->assertJsonCount(0, 'data');
    }

    public function test_brigadista_ve_solo_los_suyos_coordinador_ve_todos(): void
    {
        [$userBrig, $brig] = $this->miembro('brigadista');
        [$userCoord, $coord] = $this->miembro('coordinador');

        $electorBrig = $this->elector($brig);
        $electorCoord = $this->elector($coord);

        Interaccion::factory()->pendienteHoy()->create([
            'tenant_id' => $this->tenant->id, 'elector_id' => $electorBrig->id, 'membership_id' => $brig->id,
        ]);
        Interaccion::factory()->pendienteHoy()->create([
            'tenant_id' => $this->tenant->id, 'elector_id' => $electorCoord->id, 'membership_id' => $coord->id,
        ]);

        $brigResp = $this->actingAs($userBrig)->withSession(['tenant_id' => $this->tenant->id])->getJson('/api/agenda');
        $brigResp->assertJsonCount(1, 'data');

        $coordResp = $this->actingAs($userCoord)->withSession(['tenant_id' => $this->tenant->id])->getJson('/api/agenda');
        $coordResp->assertJsonCount(2, 'data');
    }

    public function test_busqueda_por_nombre_filtra_por_nombre_del_elector(): void
    {
        [$user, $membership] = $this->miembro('coordinador');

        $ana = $this->elector($membership, null, 'Ana López');
        $beto = $this->elector($membership, null, 'Beto Ruiz');
        Interaccion::factory()->pendienteHoy()->create([
            'tenant_id' => $this->tenant->id, 'elector_id' => $ana->id, 'membership_id' => $membership->id,
        ]);
        Interaccion::factory()->pendienteHoy()->create([
            'tenant_id' => $this->tenant->id, 'elector_id' => $beto->id, 'membership_id' => $membership->id,
        ]);

        $response = $this->actingAs($user)->withSession(['tenant_id' => $this->tenant->id])
            ->getJson('/api/agenda?q=ana');

        $response->assertOk();
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.elector.nombre', 'Ana López');
    }

    public function test_filtro_por_seccion_usa_el_numero_de_seccion(): void
    {
        [$user, $membership] = $this->miembro('coordinador');
        $otraSeccion = Seccion::query()->where('municipio_id', $this->municipio->id)->where('numero', 2)->first();

        $enUno = $this->elector($membership, $this->seccion);
        $enDos = $this->elector($membership, $otraSeccion);
        Interaccion::factory()->pendienteHoy()->create([
            'tenant_id' => $this->tenant->id, 'elector_id' => $enUno->id, 'membership_id' => $membership->id,
        ]);
        Interaccion::factory()->pendienteHoy()->create([
            'tenant_id' => $this->tenant->id, 'elector_id' => $enDos->id, 'membership_id' => $membership->id,
        ]);

        $response = $this->actingAs($user)->withSession(['tenant_id' => $this->tenant->id])
            ->getJson('/api/agenda?secciones[]='.$otraSeccion->id);

        $response->assertOk();
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.elector.seccion_numero', $otraSeccion->numero);
    }

    public function test_brigadista_solo_ve_sus_zonas_en_el_catalogo_de_secciones(): void
    {
        [$user, $membership] = $this->miembro('brigadista');
        $membership->secciones()->sync([$this->seccion->id => ['tenant_id' => $this->tenant->id]]);

        $this->actingAs($user)->withSession(['tenant_id' => $this->tenant->id])
            ->get('/agenda')
            ->assertInertia(fn ($page) => $page
                ->component('Agenda')
                ->has('secciones', 1)
                ->where('secciones.0.id', $this->seccion->id)
            );
    }

    public function test_gestion_ve_todas_las_secciones_del_municipio_en_el_catalogo(): void
    {
        [$user] = $this->miembro('coordinador');
        $total = Seccion::query()->where('municipio_id', $this->municipio->id)->count();

        $this->actingAs($user)->withSession(['tenant_id' => $this->tenant->id])
            ->get('/agenda')
            ->assertInertia(fn ($page) => $page
                ->component('Agenda')
                ->has('secciones', $total)
            );
    }

    public function test_agenda_pagina_a_25_por_pagina(): void
    {
        [$user, $membership] = $this->miembro('coordinador');

        foreach (range(1, 26) as $i) {
            $elector = $this->elector($membership);
            Interaccion::factory()->pendienteHoy()->create([
                'tenant_id' => $this->tenant->id, 'elector_id' => $elector->id, 'membership_id' => $membership->id,
            ]);
        }

        $response = $this->actingAs($user)->withSession(['tenant_id' => $this->tenant->id])
            ->getJson('/api/agenda');

        $response->assertOk();
        $response->assertJsonCount(25, 'data');
        $response->assertJsonPath('total', 26);
        $response->assertJsonPath('last_page', 2);
    }

    public function test_marcar_atendido_es_idempotente(): void
    {
        [$user, $membership] = $this->miembro('brigadista');
        $elector = $this->elector($membership);
        $interaccion = Interaccion::factory()->pendienteHoy()->create([
            'tenant_id' => $this->tenant->id, 'elector_id' => $elector->id, 'membership_id' => $membership->id,
        ]);

        $this->actingAs($user)->withSession(['tenant_id' => $this->tenant->id])
            ->putJson("/api/interacciones/{$interaccion->id}/atendido")->assertOk();

        TenantContext::set($this->tenant);
        $marca = Interaccion::query()->find($interaccion->id)->atendido_en;
        $this->assertNotNull($marca);

        $this->actingAs($user)->withSession(['tenant_id' => $this->tenant->id])
            ->putJson("/api/interacciones/{$interaccion->id}/atendido")->assertOk();

        // La segunda marca no reescribe la primera (idempotente): mismo instante persistido.
        TenantContext::set($this->tenant);
        $this->assertTrue($marca->equalTo(Interaccion::query()->find($interaccion->id)->atendido_en));
    }
}
