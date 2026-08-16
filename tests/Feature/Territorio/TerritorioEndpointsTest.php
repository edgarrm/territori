<?php

namespace Tests\Feature\Territorio;

use App\Models\Coalicion;
use App\Models\CoberturaSeccion;
use App\Models\Entidad;
use App\Models\EstadisticaSeccion;
use App\Models\Membership;
use App\Models\Municipio;
use App\Models\Partido;
use App\Models\Seccion;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TerritorioEndpointsTest extends TestCase
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
    private function tenantConPartido(?string $siglas, string $rol = 'coordinador', ?Municipio $municipio = null): array
    {
        $municipio ??= Municipio::factory()->create();
        $partido = $siglas !== null ? Partido::factory()->create(['siglas' => $siglas]) : null;
        $tenant = Tenant::factory()->create(['municipio_id' => $municipio->id, 'partido_id' => $partido?->id]);
        $user = User::factory()->create();
        Membership::create(['tenant_id' => $tenant->id, 'user_id' => $user->id, 'rol' => $rol]);

        return [$tenant, $user, $municipio];
    }

    /**
     * Criterio 6: sin `id`, resuelve al municipio del tenant activo y los
     * totales coinciden con la suma manual de sus secciones.
     */
    public function test_resumen_nivel_municipio_sin_id_resuelve_al_municipio_del_tenant(): void
    {
        $this->coalicionSigamos();
        [$tenant, $user, $municipio] = $this->tenantConPartido('MORENA');

        $seccionA = Seccion::factory()->create(['municipio_id' => $municipio->id]);
        $seccionB = Seccion::factory()->create(['municipio_id' => $municipio->id]);

        EstadisticaSeccion::factory()->create([
            'seccion_id' => $seccionA->id,
            'total_votos' => 100,
            'lista_nominal_2024' => 300,
            'votos_partidos' => ['MORENA' => 60, 'MC' => 40],
        ]);
        EstadisticaSeccion::factory()->create([
            'seccion_id' => $seccionB->id,
            'total_votos' => 80,
            'lista_nominal_2024' => 200,
            'votos_partidos' => ['MORENA' => 30, 'MC' => 50],
        ]);

        $response = $this->actingAs($user)->withSession(['tenant_id' => $tenant->id])
            ->getJson('/api/territorio/resumen?nivel=municipio');

        $response->assertOk();
        $response->assertJsonPath('secciones_total', 2);
        $response->assertJsonPath('estadistica.secciones_incluidas', 2);
        $response->assertJsonPath('estadistica.total_votos', 180);
        $response->assertJsonPath('estadistica.lista_nominal_2024', 500);
        $this->assertEqualsWithDelta(180 / 500 * 100, $response->json('estadistica.participacion_pct'), 0.001);
    }

    /**
     * Criterio 7: estado con 3 municipios donde solo 1 tiene secciones
     * cargadas => cobertura_cartografia {municipios_cargados: 1, municipios_totales: 3}.
     */
    public function test_resumen_nivel_entidad_reporta_cobertura_de_cartografia_parcial(): void
    {
        $entidad = Entidad::factory()->create();
        $municipioConSecciones = Municipio::factory()->create(['entidad_id' => $entidad->id]);
        Municipio::factory()->create(['entidad_id' => $entidad->id]);
        Municipio::factory()->create(['entidad_id' => $entidad->id]);

        Seccion::factory()->create(['municipio_id' => $municipioConSecciones->id]);
        Seccion::factory()->create(['municipio_id' => $municipioConSecciones->id]);

        [$tenant, $user] = $this->tenantConPartido('MORENA', municipio: $municipioConSecciones);

        $response = $this->actingAs($user)->withSession(['tenant_id' => $tenant->id])
            ->getJson('/api/territorio/resumen?nivel=entidad');

        $response->assertOk();
        $response->assertJsonPath('cobertura_cartografia.municipios_cargados', 1);
        $response->assertJsonPath('cobertura_cartografia.municipios_totales', 3);
    }

    /**
     * Criterio 8: brigadista con zonas asignadas solo agrega esas secciones;
     * coordinador/admin agrega todas.
     */
    public function test_brigadista_solo_agrega_sus_secciones_asignadas(): void
    {
        $municipio = Municipio::factory()->create();
        $tenant = Tenant::factory()->create(['municipio_id' => $municipio->id]);

        $seccionAsignada = Seccion::factory()->create(['municipio_id' => $municipio->id]);
        $seccionAjena = Seccion::factory()->create(['municipio_id' => $municipio->id]);

        TenantContext::set($tenant);
        CoberturaSeccion::factory()->create(['tenant_id' => $tenant->id, 'seccion_id' => $seccionAsignada->id, 'capturados' => 10, 'meta' => 20]);
        CoberturaSeccion::factory()->create(['tenant_id' => $tenant->id, 'seccion_id' => $seccionAjena->id, 'capturados' => 90, 'meta' => 100]);
        TenantContext::clear();

        $userBrigadista = User::factory()->create();
        $brigadista = Membership::create(['tenant_id' => $tenant->id, 'user_id' => $userBrigadista->id, 'rol' => 'brigadista']);
        $brigadista->secciones()->attach($seccionAsignada->id, ['tenant_id' => $tenant->id]);

        $responseBrigadista = $this->actingAs($userBrigadista)->withSession(['tenant_id' => $tenant->id])
            ->getJson('/api/territorio/resumen?nivel=municipio');

        $responseBrigadista->assertOk();
        $responseBrigadista->assertJsonPath('secciones_total', 1);
        $responseBrigadista->assertJsonPath('cobertura.capturados', 10);

        $userCoordinador = User::factory()->create();
        Membership::create(['tenant_id' => $tenant->id, 'user_id' => $userCoordinador->id, 'rol' => 'coordinador']);

        $responseCoordinador = $this->actingAs($userCoordinador)->withSession(['tenant_id' => $tenant->id])
            ->getJson('/api/territorio/resumen?nivel=municipio');

        $responseCoordinador->assertOk();
        $responseCoordinador->assertJsonPath('secciones_total', 2);
        $responseCoordinador->assertJsonPath('cobertura.capturados', 100);
    }

    /**
     * Criterio 9: geojson a nivel entidad trae un Feature por municipio, no
     * un polígono fundido de estado.
     */
    public function test_geojson_nivel_entidad_trae_un_feature_por_municipio(): void
    {
        $entidad = Entidad::factory()->create();
        $municipioA = Municipio::factory()->create(['entidad_id' => $entidad->id]);
        $municipioB = Municipio::factory()->create(['entidad_id' => $entidad->id]);

        [$tenant, $user] = $this->tenantConPartido('MORENA', municipio: $municipioA);

        $response = $this->actingAs($user)->withSession(['tenant_id' => $tenant->id])
            ->getJson('/api/territorio/geojson?nivel=entidad&id='.$entidad->id);

        $response->assertOk();
        $response->assertJsonPath('type', 'FeatureCollection');
        $municipioIds = collect($response->json('features'))->pluck('properties.municipio_id');

        $this->assertCount(2, $response->json('features'));
        $this->assertTrue($municipioIds->contains($municipioA->id));
        $this->assertTrue($municipioIds->contains($municipioB->id));
    }

    public function test_geojson_nivel_municipio_trae_un_solo_feature(): void
    {
        [$tenant, $user, $municipio] = $this->tenantConPartido('MORENA');

        $response = $this->actingAs($user)->withSession(['tenant_id' => $tenant->id])
            ->getJson('/api/territorio/geojson?nivel=municipio');

        $response->assertOk();
        $this->assertCount(1, $response->json('features'));
        $response->assertJsonPath('features.0.properties.municipio_id', $municipio->id);
    }

    /**
     * Criterio 10: dos tenants con partidos distintos sobre el mismo
     * municipio ven estatus de competitividad coherente con su propia
     * perspectiva, sin fuga entre tenants.
     */
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
            ->getJson('/api/territorio/resumen?nivel=municipio');
        $respuestaPan = $this->actingAs($userPan)->withSession(['tenant_id' => $tenantPan->id])
            ->getJson('/api/territorio/resumen?nivel=municipio');

        $respuestaMorena->assertJsonPath('estadistica.competitividad.estatus_slug', 'ganada-franca');
        $respuestaMorena->assertJsonPath('estadistica.competitividad.diferencia_votos', 77);

        $respuestaPan->assertJsonPath('estadistica.competitividad.estatus_slug', 'perdida');
        $respuestaPan->assertJsonPath('estadistica.competitividad.diferencia_votos', -77);
    }
}
