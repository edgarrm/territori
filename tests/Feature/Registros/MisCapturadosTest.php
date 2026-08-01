<?php

namespace Tests\Feature\Registros;

use App\Models\AvisoPrivacidad;
use App\Models\Elector;
use App\Models\Membership;
use App\Models\Municipio;
use App\Models\Seccion;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\CartografiaSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Vista personal de capturados: la pantalla de aterrizaje del brigadista, cuyo
 * perfil es de captura y no alcanza la lista global ni el detalle de sección.
 */
class MisCapturadosTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private AvisoPrivacidad $aviso;

    private Seccion $seccion;

    private Seccion $otraSeccion;

    private User $user;

    private Membership $brigadista;

    private Membership $otro;

    protected function setUp(): void
    {
        parent::setUp();

        (new CartografiaSeeder)->run(base_path('tests/Fixtures/cartografia/99-test'));
        $municipio = Municipio::query()->where('clave', 12)->first();
        $this->tenant = Tenant::factory()->create(['municipio_id' => $municipio->id]);
        TenantContext::set($this->tenant);
        $this->aviso = AvisoPrivacidad::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->seccion = Seccion::query()->where('municipio_id', $municipio->id)->where('numero', 1)->first();
        $this->otraSeccion = Seccion::query()->where('municipio_id', $municipio->id)->where('numero', 2)->first();

        $this->user = User::factory()->create();
        $this->brigadista = Membership::create([
            'tenant_id' => $this->tenant->id,
            'user_id' => $this->user->id,
            'rol' => 'brigadista',
        ]);
        $this->brigadista->secciones()->attach($this->seccion->id, ['tenant_id' => $this->tenant->id]);

        $this->otro = Membership::create([
            'tenant_id' => $this->tenant->id,
            'user_id' => User::factory()->create()->id,
            'rol' => 'brigadista',
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function elector(Membership $de, array $overrides = []): Elector
    {
        TenantContext::set($this->tenant);

        return Elector::factory()->create(array_merge([
            'tenant_id' => $this->tenant->id,
            'seccion_id' => $this->seccion->id,
            'membership_id' => $de->id,
            'aviso_privacidad_id' => $this->aviso->id,
        ], $overrides));
    }

    private function comoBrigadista(): TestResponse
    {
        return $this->actingAs($this->user)->withSession(['tenant_id' => $this->tenant->id])
            ->getJson(route('mis-capturados.data'));
    }

    public function test_pagina_renderiza_con_los_catalogos_de_filtro(): void
    {
        $this->actingAs($this->user)->withSession(['tenant_id' => $this->tenant->id])
            ->get(route('mis-capturados'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('MisCapturados')
                ->has('modos', 4)
                // Solo sus zonas asignadas, no todo el municipio.
                ->has('seccionesFiltro', 1)
                ->where('seccionesFiltro.0.id', $this->seccion->id));
    }

    public function test_solo_devuelve_los_electores_que_el_capturo(): void
    {
        $mio = $this->elector($this->brigadista);
        $this->elector($this->otro);

        $respuesta = $this->comoBrigadista();

        $respuesta->assertOk();
        $respuesta->assertJsonCount(1, 'data');
        $respuesta->assertJsonPath('data.0.id', $mio->id);
    }

    public function test_ve_la_pii_completa_de_sus_propios_registros(): void
    {
        $mio = $this->elector($this->brigadista);

        $this->comoBrigadista()->assertJsonPath('data.0.telefono', $mio->telefono);
    }

    public function test_filtra_por_seccion(): void
    {
        $enSeccion = $this->elector($this->brigadista);
        $this->elector($this->brigadista, ['seccion_id' => $this->otraSeccion->id]);

        $url = route('mis-capturados.data').'?'.http_build_query(['secciones' => [$this->seccion->id]]);
        $respuesta = $this->actingAs($this->user)->withSession(['tenant_id' => $this->tenant->id])
            ->getJson($url);

        $respuesta->assertOk();
        $respuesta->assertJsonCount(1, 'data');
        $respuesta->assertJsonPath('data.0.id', $enSeccion->id);
    }

    public function test_filtra_por_tipo_de_captura(): void
    {
        $porEvento = $this->elector($this->brigadista, ['modo_captura' => 'evento']);
        $this->elector($this->brigadista, ['modo_captura' => 'enlace_seccional']);

        $url = route('mis-capturados.data').'?'.http_build_query(['modos' => ['evento']]);
        $respuesta = $this->actingAs($this->user)->withSession(['tenant_id' => $this->tenant->id])
            ->getJson($url);

        $respuesta->assertOk();
        $respuesta->assertJsonCount(1, 'data');
        $respuesta->assertJsonPath('data.0.id', $porEvento->id);
    }

    public function test_scoped_por_tenant(): void
    {
        $otroTenant = Tenant::factory()->create();
        TenantContext::set($otroTenant);
        Elector::factory()->create([
            'tenant_id' => $otroTenant->id,
            'seccion_id' => $this->seccion->id,
            'membership_id' => $this->brigadista->id,
        ]);

        $this->comoBrigadista()->assertJsonCount(0, 'data');
    }

    public function test_el_enlace_no_accede(): void
    {
        $enlace = User::factory()->create();
        Membership::create([
            'tenant_id' => $this->tenant->id,
            'user_id' => $enlace->id,
            'rol' => 'enlace',
        ]);

        $this->actingAs($enlace)->withSession(['tenant_id' => $this->tenant->id])
            ->getJson(route('mis-capturados.data'))
            ->assertForbidden();
    }

    public function test_login_de_brigadista_aterriza_en_sus_capturados(): void
    {
        $this->assertSame('mis-capturados', $this->brigadista->rutaInicial());
    }

    /**
     * Ya autenticado, /login lo devuelve a su ruta inicial. El default de
     * Fortify (/dashboard) le daría 403.
     */
    public function test_brigadista_autenticado_en_login_va_a_sus_capturados(): void
    {
        $this->actingAs($this->user)->withSession(['tenant_id' => $this->tenant->id])
            ->get(route('login'))
            ->assertRedirect(route('mis-capturados'));
    }
}
