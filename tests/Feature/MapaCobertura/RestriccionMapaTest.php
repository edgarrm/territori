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
 * El brigadista solo ve/consulta sus secciones asignadas en el mapa; gestión
 * (coordinador/admin) ve todas.
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

    public function test_brigadista_solo_ve_sus_secciones_en_cobertura(): void
    {
        [$user, $brigadista] = $this->miembro('brigadista');
        $brigadista->secciones()->attach($this->seccion(1)->id, ['tenant_id' => $this->tenant->id]);

        $response = $this->actingAs($user)->withSession(['tenant_id' => $this->tenant->id])
            ->getJson('/api/cobertura.geojson');

        $response->assertOk();
        $features = $response->json('features');
        $this->assertCount(1, $features);
        $this->assertSame($this->seccion(1)->id, $features[0]['properties']['seccion_id']);
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

    public function test_brigadista_no_ve_resumen_de_seccion_ajena(): void
    {
        [$user, $brigadista] = $this->miembro('brigadista');
        $brigadista->secciones()->attach($this->seccion(1)->id, ['tenant_id' => $this->tenant->id]);

        $this->actingAs($user)->withSession(['tenant_id' => $this->tenant->id])
            ->getJson('/api/secciones/'.$this->seccion(2)->id.'/resumen')
            ->assertForbidden();

        // Su propia sección sí.
        $this->actingAs($user)->withSession(['tenant_id' => $this->tenant->id])
            ->getJson('/api/secciones/'.$this->seccion(1)->id.'/resumen')
            ->assertOk();
    }

    public function test_brigadista_no_ve_detalle_ni_electores_de_seccion_ajena(): void
    {
        [$user, $brigadista] = $this->miembro('brigadista');
        $brigadista->secciones()->attach($this->seccion(1)->id, ['tenant_id' => $this->tenant->id]);
        $ajena = $this->seccion(2);

        $this->actingAs($user)->withSession(['tenant_id' => $this->tenant->id])
            ->get('/secciones/'.$ajena->id)->assertForbidden();

        $this->actingAs($user)->withSession(['tenant_id' => $this->tenant->id])
            ->getJson('/api/secciones/'.$ajena->id.'/electores')->assertForbidden();
    }

    public function test_gestion_ve_detalle_de_cualquier_seccion(): void
    {
        [$user] = $this->miembro('coordinador');

        $this->actingAs($user)->withSession(['tenant_id' => $this->tenant->id])
            ->getJson('/api/secciones/'.$this->seccion(2)->id.'/resumen')
            ->assertOk();
    }
}
