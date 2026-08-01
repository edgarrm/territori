<?php

namespace Tests\Feature\MapaCobertura;

use App\Models\Membership;
use App\Models\Municipio;
use App\Models\Seccion;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\CartografiaSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\AsignaZonas;
use Tests\TestCase;

/**
 * El mapa y el detalle de sección son vistas de análisis: solo gestión
 * (coordinador/admin) entra. El brigadista, cuyo perfil es de captura, recibe
 * 403 incluso en las secciones que tiene asignadas.
 */
class RestriccionMapaTest extends TestCase
{
    use AsignaZonas;
    use RefreshDatabase;

    private Tenant $tenant;

    private Municipio $municipio;

    protected function setUp(): void
    {
        parent::setUp();

        (new CartografiaSeeder)->run(base_path('tests/Fixtures/cartografia/99-test'));
        $this->municipio = Municipio::query()->where('clave', 12)->first();
        $this->tenant = Tenant::factory()->create(['municipio_id' => $this->municipio->id]);
        TenantContext::set($this->tenant);
    }

    private function miembro(string $rol): array
    {
        $user = User::factory()->create();
        $membership = Membership::create(['tenant_id' => $this->tenant->id, 'user_id' => $user->id, 'rol' => $rol]);

        return [$user, $membership];
    }

    private function seccion(int $numero): Seccion
    {
        return Seccion::query()->where('municipio_id', $this->municipio->id)->where('numero', $numero)->first();
    }

    /**
     * Ni siquiera en su propia zona: el brigadista no tiene acceso a ninguna de
     * las vistas de análisis.
     */
    public function test_brigadista_no_accede_a_las_vistas_de_analisis(): void
    {
        [$user, $brigadista] = $this->miembro('brigadista');
        $propia = $this->seccion(1);
        $brigadista->secciones()->attach($propia->id, ['tenant_id' => $this->tenant->id]);

        $sesion = $this->actingAs($user)->withSession(['tenant_id' => $this->tenant->id]);

        $sesion->get('/mapa')->assertForbidden();
        $sesion->getJson('/api/cobertura.geojson')->assertForbidden();
        $sesion->get('/prioridades')->assertForbidden();
        $sesion->getJson('/api/secciones/'.$propia->id.'/resumen')->assertForbidden();
        $sesion->get('/secciones/'.$propia->id)->assertForbidden();
        $sesion->getJson('/api/secciones/'.$propia->id.'/electores')->assertForbidden();
    }

    public function test_gestion_ve_todas_las_secciones_en_cobertura(): void
    {
        [$user] = $this->miembro('coordinador');
        $total = Seccion::query()->where('municipio_id', $this->municipio->id)->count();

        $response = $this->actingAs($user)->withSession(['tenant_id' => $this->tenant->id])
            ->getJson('/api/cobertura.geojson');

        $response->assertOk();
        $this->assertCount($total, $response->json('features'));
        $this->assertGreaterThan(1, $total);
    }

    public function test_enlace_tampoco_accede_al_mapa(): void
    {
        [$user] = $this->miembro('enlace');

        $this->actingAs($user)->withSession(['tenant_id' => $this->tenant->id])
            ->get('/mapa')->assertForbidden();
    }

    public function test_gestion_ve_detalle_de_cualquier_seccion(): void
    {
        [$user] = $this->miembro('coordinador');

        $this->actingAs($user)->withSession(['tenant_id' => $this->tenant->id])
            ->getJson('/api/secciones/'.$this->seccion(2)->id.'/resumen')
            ->assertOk();
    }
}
