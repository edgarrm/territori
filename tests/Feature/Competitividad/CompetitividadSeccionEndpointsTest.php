<?php

namespace Tests\Feature\Competitividad;

use App\Models\Coalicion;
use App\Models\EstadisticaSeccion;
use App\Models\Membership;
use App\Models\Municipio;
use App\Models\Partido;
use App\Models\Seccion;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class CompetitividadSeccionEndpointsTest extends TestCase
{
    use RefreshDatabase;

    private function coalicionSigamos(): Coalicion
    {
        return Coalicion::factory()->create([
            'nombre' => 'Sigamos Haciendo Historia',
            'anio' => 2024,
            'partidos' => ['MORENA', 'PVEM'],
        ]);
    }

    /**
     * @return array{0: Tenant, 1: User, 2: Municipio}
     */
    private function tenantConPartido(?string $siglas, string $rol = 'coordinador'): array
    {
        $municipio = Municipio::factory()->create();
        $partido = $siglas !== null ? Partido::factory()->create(['siglas' => $siglas]) : null;
        $tenant = Tenant::factory()->create([
            'municipio_id' => $municipio->id,
            'partido_id' => $partido?->id,
        ]);
        $user = User::factory()->create();
        Membership::create(['tenant_id' => $tenant->id, 'user_id' => $user->id, 'rol' => $rol]);

        return [$tenant, $user, $municipio];
    }

    public function test_geojson_incluye_competitividad_diferencia_y_tipo_seccion(): void
    {
        $this->coalicionSigamos();
        [$tenant, $user, $municipio] = $this->tenantConPartido('MORENA');
        $seccion = Seccion::factory()->create(['municipio_id' => $municipio->id, 'lista_nominal' => 1200]);
        EstadisticaSeccion::factory()->create([
            'seccion_id' => $seccion->id,
            'votos_partidos' => ['MORENA' => 100, 'PVEM' => 56, 'MC' => 79],
        ]);

        $response = $this->actingAs($user)->withSession(['tenant_id' => $tenant->id])
            ->getJson('/api/cobertura.geojson');

        $response->assertOk();
        $propiedades = collect($response->json('features'))
            ->firstWhere('properties.seccion_id', $seccion->id)['properties'];

        $this->assertSame('ganada_franca', $propiedades['competitividad']);
        $this->assertSame(77, $propiedades['diferencia_votos']);
        $this->assertSame('alfa', $propiedades['tipo_seccion']);
    }

    public function test_geojson_sin_partido_configurado_devuelve_sin_datos(): void
    {
        [$tenant, $user, $municipio] = $this->tenantConPartido(null);
        $seccion = Seccion::factory()->create(['municipio_id' => $municipio->id]);
        EstadisticaSeccion::factory()->create([
            'seccion_id' => $seccion->id,
            'votos_partidos' => ['MORENA' => 100, 'MC' => 50],
        ]);

        $response = $this->actingAs($user)->withSession(['tenant_id' => $tenant->id])
            ->getJson('/api/cobertura.geojson');

        $propiedades = collect($response->json('features'))
            ->firstWhere('properties.seccion_id', $seccion->id)['properties'];

        $this->assertSame('sin_datos', $propiedades['competitividad']);
        $this->assertNull($propiedades['diferencia_votos']);
    }

    public function test_geojson_sin_estadistica_no_rompe(): void
    {
        [$tenant, $user, $municipio] = $this->tenantConPartido('MORENA');
        $seccion = Seccion::factory()->create(['municipio_id' => $municipio->id]);

        $response = $this->actingAs($user)->withSession(['tenant_id' => $tenant->id])
            ->getJson('/api/cobertura.geojson');

        $response->assertOk();
        $propiedades = collect($response->json('features'))
            ->firstWhere('properties.seccion_id', $seccion->id)['properties'];

        $this->assertSame('sin_datos', $propiedades['competitividad']);
        $this->assertNull($propiedades['diferencia_votos']);
    }

    public function test_resumen_incluye_competitividad_y_tipo_seccion(): void
    {
        $this->coalicionSigamos();
        [$tenant, $user, $municipio] = $this->tenantConPartido('MORENA');
        $seccion = Seccion::factory()->create(['municipio_id' => $municipio->id, 'lista_nominal' => 600]);
        EstadisticaSeccion::factory()->create([
            'seccion_id' => $seccion->id,
            'ganador_bloque' => 'Morena-PVEM',
            'votos_partidos' => ['MORENA' => 100, 'PVEM' => 56, 'MC' => 79],
        ]);

        $response = $this->actingAs($user)->withSession(['tenant_id' => $tenant->id])
            ->getJson("/api/secciones/{$seccion->id}/resumen");

        $response->assertOk();
        $response->assertJsonPath('electoral_2024.competitividad', 'ganada_franca');
        $response->assertJsonPath('electoral_2024.diferencia_votos', 77);
        $response->assertJsonPath('electoral_2024.votos_bloque_propio', 156);
        $response->assertJsonPath('electoral_2024.votos_mejor_rival', 79);
        $response->assertJsonPath('electoral_2024.bloque_propio', 'Sigamos Haciendo Historia');
        $response->assertJsonPath('tipo_seccion', 'beta');
    }

    public function test_prioridades_incluye_competitividad_diferencia_y_tipo(): void
    {
        $this->coalicionSigamos();
        [$tenant, $user, $municipio] = $this->tenantConPartido('MORENA');
        $seccion = Seccion::factory()->create(['municipio_id' => $municipio->id, 'lista_nominal' => 100]);
        EstadisticaSeccion::factory()->create([
            'seccion_id' => $seccion->id,
            'votos_partidos' => ['MORENA' => 100, 'MC' => 50],
        ]);

        $response = $this->actingAs($user)->withSession(['tenant_id' => $tenant->id])->get('/prioridades');

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('Prioridades')
            ->where('secciones.0.competitividad', 'ganada_franca')
            ->where('secciones.0.diferencia_votos', 50)
            ->where('secciones.0.tipo_seccion', 'gama'));
    }

    public function test_perspectiva_por_tenant_sin_fugas_entre_partidos(): void
    {
        $this->coalicionSigamos();
        $municipio = Municipio::factory()->create();
        $seccion = Seccion::factory()->create(['municipio_id' => $municipio->id]);
        EstadisticaSeccion::factory()->create([
            'seccion_id' => $seccion->id,
            'votos_partidos' => ['MORENA' => 100, 'PVEM' => 56, 'PAN' => 79],
        ]);

        $morena = Partido::factory()->create(['siglas' => 'MORENA']);
        $pan = Partido::factory()->create(['siglas' => 'PAN']);

        $tenantMorena = Tenant::factory()->create(['municipio_id' => $municipio->id, 'partido_id' => $morena->id]);
        $userMorena = User::factory()->create();
        Membership::create(['tenant_id' => $tenantMorena->id, 'user_id' => $userMorena->id, 'rol' => 'coordinador']);

        $tenantPan = Tenant::factory()->create(['municipio_id' => $municipio->id, 'partido_id' => $pan->id]);
        $userPan = User::factory()->create();
        Membership::create(['tenant_id' => $tenantPan->id, 'user_id' => $userPan->id, 'rol' => 'coordinador']);

        $respuestaMorena = $this->actingAs($userMorena)->withSession(['tenant_id' => $tenantMorena->id])
            ->getJson('/api/cobertura.geojson');
        $respuestaPan = $this->actingAs($userPan)->withSession(['tenant_id' => $tenantPan->id])
            ->getJson('/api/cobertura.geojson');

        $propMorena = collect($respuestaMorena->json('features'))->firstWhere('properties.seccion_id', $seccion->id)['properties'];
        $propPan = collect($respuestaPan->json('features'))->firstWhere('properties.seccion_id', $seccion->id)['properties'];

        $this->assertSame('ganada_franca', $propMorena['competitividad']);
        $this->assertSame(77, $propMorena['diferencia_votos']);

        $this->assertSame('perdida', $propPan['competitividad']);
        $this->assertSame(-77, $propPan['diferencia_votos']);
    }

    public function test_prioridades_oculta_columna_de_estatus_cuando_indicador_esta_apagado(): void
    {
        [$tenant, $user, $municipio] = $this->tenantConPartido('MORENA');
        $tenant->update(['settings' => ['indicadores' => ['competitividad' => false]]]);
        Seccion::factory()->create(['municipio_id' => $municipio->id]);

        $response = $this->actingAs($user)->withSession(['tenant_id' => $tenant->id])->get('/prioridades');

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('Prioridades')
            ->where('mostrarCompetitividad', false));
    }
}
