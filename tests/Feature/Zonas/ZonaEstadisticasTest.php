<?php

namespace Tests\Feature\Zonas;

use App\Actions\Cobertura\AgregarCoberturaTerritorial;
use App\Actions\Estadisticas\AgregarEstadisticaTerritorial;
use App\Actions\Zonas\CalcularInterseccionZona;
use App\Models\Coalicion;
use App\Models\CoberturaSeccion;
use App\Models\EstadisticaSeccion;
use App\Models\Membership;
use App\Models\Partido;
use App\Models\Seccion;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Zona;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * F7: intersección zona⨯sección + estadísticas/cobertura agregadas de zona,
 * reusando las Actions de F5. Cubre los 8 criterios de aceptación de
 * `_ai/specs/zonas-cobertura-estadisticas.spec.md`.
 */
class ZonaEstadisticasTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{0: Tenant, 1: User}
     */
    private function setupCampana(string $rol = 'coordinador'): array
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create();
        Membership::factory()->create(['tenant_id' => $tenant->id, 'user_id' => $user->id, 'rol' => $rol]);
        TenantContext::set($tenant);

        return [$tenant, $user];
    }

    /**
     * Crea una sección con geometría de cuadro [x1,y1]-[x2,y2] en 4326.
     */
    private function seccionConCuadro(float $x1, float $y1, float $x2, float $y2): Seccion
    {
        $seccion = Seccion::factory()->create();
        $this->fijarGeom('secciones', $seccion->id, $x1, $y1, $x2, $y2);

        return $seccion;
    }

    /**
     * Crea una zona (sin geom del factory) y le fija un cuadro propio.
     *
     * @param  array<string, mixed>  $atributos
     */
    private function zonaConCuadro(float $x1, float $y1, float $x2, float $y2, array $atributos = []): Zona
    {
        $zona = Zona::factory()->sinGeom()->create($atributos);
        $this->fijarGeom('zonas', $zona->id, $x1, $y1, $x2, $y2);

        return $zona->fresh();
    }

    private function fijarGeom(string $tabla, int $id, float $x1, float $y1, float $x2, float $y2): void
    {
        $geojson = [
            'type' => 'Polygon',
            'coordinates' => [[[$x1, $y1], [$x1, $y2], [$x2, $y2], [$x2, $y1], [$x1, $y1]]],
        ];

        DB::statement(
            "update {$tabla} set geom = ST_Multi(ST_SetSRID(ST_GeomFromGeoJSON(?), 4326)) where id = ?",
            [json_encode($geojson), $id]
        );
    }

    // --- Criterio 1 + 2: cobertura completa/parcial/exacta 0.5 ---
    public function test_zona_registra_interseccion_completa_parcial_y_solo_agrega_las_de_al_menos_50_por_ciento(): void
    {
        [$tenant, $user] = $this->setupCampana();

        $seccionA = $this->seccionConCuadro(0, 0, 1, 1);
        $seccionB = $this->seccionConCuadro(1, 0, 2, 1);
        $seccionC = $this->seccionConCuadro(2, 0, 3, 1);
        // D solo se cubre al 30% (zona llega hasta x=3.3, D va de x=3 a x=4).
        $seccionD = $this->seccionConCuadro(3, 0, 4, 1);

        $zona = $this->zonaConCuadro(0, 0, 3.3, 1, ['nombre' => 'Franja']);
        (new CalcularInterseccionZona)->handle($zona);

        $filas = DB::table('zona_seccion')->where('zona_id', $zona->id)->get()->keyBy('seccion_id');

        $this->assertCount(4, $filas);
        $this->assertEqualsWithDelta(1.0, (float) $filas[$seccionA->id]->pct_interseccion, 0.001);
        $this->assertEqualsWithDelta(1.0, (float) $filas[$seccionB->id]->pct_interseccion, 0.001);
        $this->assertEqualsWithDelta(1.0, (float) $filas[$seccionC->id]->pct_interseccion, 0.001);
        $this->assertEqualsWithDelta(0.3, (float) $filas[$seccionD->id]->pct_interseccion, 0.001);
        $this->assertSame($tenant->id, $filas[$seccionA->id]->tenant_id);

        // resumen(): solo A, B, C entran (D queda excluida, < 0.5).
        $response = $this->actingAs($user)->withSession(['tenant_id' => $tenant->id])
            ->getJson("/api/zonas/{$zona->id}/resumen");

        $response->assertOk();
        $response->assertJsonPath('secciones_total', 3);
    }

    // --- Criterio 2 (aislado): exactamente 0.5 SÍ cuenta ---
    public function test_pct_interseccion_exactamente_0_5_si_se_incluye(): void
    {
        $this->setupCampana();

        $seccion = $this->seccionConCuadro(0, 0, 1, 1);
        $zona = $this->zonaConCuadro(0, 0, 0.5, 1, ['nombre' => 'Mitad']);
        (new CalcularInterseccionZona)->handle($zona);

        $fila = DB::table('zona_seccion')->where('zona_id', $zona->id)->where('seccion_id', $seccion->id)->first();
        $this->assertEqualsWithDelta(0.5, (float) $fila->pct_interseccion, 0.0001);

        $seccionIds = DB::table('zona_seccion')
            ->where('zona_id', $zona->id)
            ->where('pct_interseccion', '>=', 0.5)
            ->pluck('seccion_id');

        $this->assertTrue($seccionIds->contains($seccion->id));
    }

    // --- Criterio 3: actualizar geometría recalcula completo (no acumula) ---
    public function test_recalcular_zona_borra_las_filas_viejas_antes_de_insertar_las_nuevas(): void
    {
        $this->setupCampana();

        $seccionA = $this->seccionConCuadro(0, 0, 1, 1);
        $seccionB = $this->seccionConCuadro(5, 0, 6, 1);

        $zona = $this->zonaConCuadro(0, 0, 1, 1, ['nombre' => 'Movible']);
        (new CalcularInterseccionZona)->handle($zona);
        $this->assertSame(1, DB::table('zona_seccion')->where('zona_id', $zona->id)->count());
        $this->assertTrue(DB::table('zona_seccion')->where('zona_id', $zona->id)->where('seccion_id', $seccionA->id)->exists());

        $this->fijarGeom('zonas', $zona->id, 5, 0, 6, 1);
        (new CalcularInterseccionZona)->handle($zona->fresh());

        $filas = DB::table('zona_seccion')->where('zona_id', $zona->id)->get();
        $this->assertCount(1, $filas);
        $this->assertSame($seccionB->id, $filas->first()->seccion_id);
    }

    // --- Criterio 4: equivalencia con llamar F5 a mano ---
    public function test_resumen_de_zona_coincide_con_llamar_las_actions_de_f5_a_mano(): void
    {
        $this->coalicionSigamos();
        [$tenant, $user] = $this->setupCampana();
        $partido = Partido::factory()->create(['siglas' => 'MORENA']);
        $tenant->update(['partido_id' => $partido->id]);

        $seccionA = $this->seccionConCuadro(0, 0, 1, 1);
        $seccionB = $this->seccionConCuadro(1, 0, 2, 1);

        EstadisticaSeccion::factory()->create([
            'seccion_id' => $seccionA->id,
            'total_votos' => 100,
            'lista_nominal_2024' => 300,
            'votos_partidos' => ['MORENA' => 60, 'PAN' => 40],
        ]);
        EstadisticaSeccion::factory()->create([
            'seccion_id' => $seccionB->id,
            'total_votos' => 80,
            'lista_nominal_2024' => 200,
            'votos_partidos' => ['MORENA' => 30, 'PAN' => 50],
        ]);

        CoberturaSeccion::factory()->create(['tenant_id' => $tenant->id, 'seccion_id' => $seccionA->id, 'capturados' => 10, 'meta' => 20]);
        CoberturaSeccion::factory()->create(['tenant_id' => $tenant->id, 'seccion_id' => $seccionB->id, 'capturados' => 5, 'meta' => 10]);

        $zona = $this->zonaConCuadro(0, 0, 2, 1, ['nombre' => 'Doble']);
        (new CalcularInterseccionZona)->handle($zona);

        $response = $this->actingAs($user)->withSession(['tenant_id' => $tenant->id])
            ->getJson("/api/zonas/{$zona->id}/resumen");
        $response->assertOk();

        // Cálculo manual, exactamente con las mismas Actions de F5.
        TenantContext::set($tenant);
        $seccionIds = collect([$seccionA->id, $seccionB->id]);
        $coaliciones = Coalicion::where('anio', 2024)->get();
        $categorias = $tenant->categoriasCompetitividad();

        $estadisticaEsperada = (new AgregarEstadisticaTerritorial)->handle(
            $seccionIds,
            'MORENA',
            $coaliciones,
            $categorias,
            'votos',
            Partido::all(),
        );
        $coberturaEsperada = (new AgregarCoberturaTerritorial)->handle($seccionIds);

        // Ambos lados pasan por el mismo round-trip JSON (json_encode
        // colapsa floats "enteros" como 36.0 a 36): sin esto la comparación
        // fallaría por un artefacto de serialización, no por una diferencia
        // real de datos.
        $normalizar = fn (array $valor): mixed => json_decode(json_encode($valor), true);

        $this->assertSame($normalizar($estadisticaEsperada), $response->json('estadistica'));
        $this->assertSame($normalizar($coberturaEsperada), $response->json('cobertura'));
    }

    // --- Criterio 5: nivel intermedio agrega recursivamente por hijas() ---
    public function test_zona_nivel_estado_agrega_las_secciones_de_todas_sus_zonas_hijas(): void
    {
        [$tenant, $user] = $this->setupCampana();

        $seccionA = $this->seccionConCuadro(0, 0, 1, 1);
        $seccionB = $this->seccionConCuadro(10, 0, 11, 1);

        CoberturaSeccion::factory()->create(['tenant_id' => $tenant->id, 'seccion_id' => $seccionA->id, 'capturados' => 10, 'meta' => 20]);
        CoberturaSeccion::factory()->create(['tenant_id' => $tenant->id, 'seccion_id' => $seccionB->id, 'capturados' => 15, 'meta' => 20]);

        $estado = Zona::factory()->sinGeom()->create(['nombre' => 'Estado', 'tipo' => 'estado']);

        $ciudadUno = $this->zonaConCuadro(0, 0, 1, 1, ['nombre' => 'Ciudad Uno', 'tipo' => 'ciudad', 'zona_padre_id' => $estado->id]);
        (new CalcularInterseccionZona)->handle($ciudadUno);

        $ciudadDos = $this->zonaConCuadro(10, 0, 11, 1, ['nombre' => 'Ciudad Dos', 'tipo' => 'ciudad', 'zona_padre_id' => $estado->id]);
        (new CalcularInterseccionZona)->handle($ciudadDos);

        $response = $this->actingAs($user)->withSession(['tenant_id' => $tenant->id])
            ->getJson("/api/zonas/{$estado->id}/resumen");

        $response->assertOk();
        $response->assertJsonPath('secciones_total', 2);
        // Cobertura sumada de ambas zonas hijas (10+15 capturados de las dos
        // secciones), prueba de que la unión recursiva sí llegó a las hojas.
        $response->assertJsonPath('cobertura.capturados', 25);
    }

    // --- Criterio 6: zona hoja sin ninguna sección relacionada ---
    public function test_zona_hoja_sin_secciones_relacionadas_no_lanza_excepcion(): void
    {
        [$tenant, $user] = $this->setupCampana();

        // Cuadro lejos de cualquier sección (que no tiene geom en este test).
        $zona = $this->zonaConCuadro(100, 40, 101, 41, ['nombre' => 'Fuera de México']);
        (new CalcularInterseccionZona)->handle($zona);

        $this->assertSame(0, DB::table('zona_seccion')->where('zona_id', $zona->id)->count());

        $response = $this->actingAs($user)->withSession(['tenant_id' => $tenant->id])
            ->getJson("/api/zonas/{$zona->id}/resumen");

        $response->assertOk();
        $response->assertJsonPath('secciones_total', 0);
        $response->assertJsonPath('estadistica.secciones_incluidas', 0);
    }

    // --- Criterio 8: aislamiento entre tenants sobre las mismas secciones globales ---
    public function test_resumen_de_zona_usa_la_cobertura_del_propio_tenant_sin_fuga(): void
    {
        [$tenantUno, $userUno] = $this->setupCampana();
        $seccion = $this->seccionConCuadro(0, 0, 1, 1);
        $zonaUno = $this->zonaConCuadro(0, 0, 1, 1, ['nombre' => 'Zona tenant uno']);
        (new CalcularInterseccionZona)->handle($zonaUno);
        CoberturaSeccion::factory()->create(['tenant_id' => $tenantUno->id, 'seccion_id' => $seccion->id, 'capturados' => 40, 'meta' => 100]);

        TenantContext::clear();
        [$tenantDos, $userDos] = $this->setupCampana();
        $zonaDos = $this->zonaConCuadro(0, 0, 1, 1, ['nombre' => 'Zona tenant dos']);
        (new CalcularInterseccionZona)->handle($zonaDos);
        CoberturaSeccion::factory()->create(['tenant_id' => $tenantDos->id, 'seccion_id' => $seccion->id, 'capturados' => 9, 'meta' => 100]);

        $respuestaUno = $this->actingAs($userUno)->withSession(['tenant_id' => $tenantUno->id])
            ->getJson("/api/zonas/{$zonaUno->id}/resumen");
        $respuestaDos = $this->actingAs($userDos)->withSession(['tenant_id' => $tenantDos->id])
            ->getJson("/api/zonas/{$zonaDos->id}/resumen");

        $respuestaUno->assertJsonPath('cobertura.capturados', 40);
        $respuestaDos->assertJsonPath('cobertura.capturados', 9);
    }

    // --- CrearZona/ActualizarZona (F6) deben disparar la intersección síncrona ---
    public function test_crear_zona_con_geom_calcula_zona_seccion_de_inmediato(): void
    {
        $this->withoutVite();
        [$tenant, $user] = $this->setupCampana();
        $this->seccionConCuadro(0, 0, 1, 1);

        $this->actingAs($user)->withSession(['tenant_id' => $tenant->id])
            ->post('/zonas', [
                'nombre' => 'Zona con geom',
                'tipo' => 'colonia',
                'geom' => [
                    'type' => 'Polygon',
                    'coordinates' => [[[0, 0], [0, 1], [1, 1], [1, 0], [0, 0]]],
                ],
            ])->assertSessionHasNoErrors();

        TenantContext::set($tenant);
        $zona = Zona::query()->where('nombre', 'Zona con geom')->firstOrFail();
        $this->assertSame(1, DB::table('zona_seccion')->where('zona_id', $zona->id)->count());
    }

    public function test_zona_mundial_sin_geom_no_calcula_ninguna_interseccion(): void
    {
        $this->withoutVite();
        [$tenant, $user] = $this->setupCampana();

        $this->actingAs($user)->withSession(['tenant_id' => $tenant->id])
            ->post('/zonas', ['nombre' => 'Mundial', 'tipo' => 'mundial'])
            ->assertSessionHasNoErrors();

        TenantContext::set($tenant);
        $zona = Zona::query()->where('nombre', 'Mundial')->firstOrFail();
        $this->assertSame(0, DB::table('zona_seccion')->where('zona_id', $zona->id)->count());
    }

    public function test_actualizar_geom_de_zona_recalcula_zona_seccion(): void
    {
        $this->withoutVite();
        [$tenant, $user] = $this->setupCampana();
        $this->seccionConCuadro(0, 0, 1, 1);
        $seccionB = $this->seccionConCuadro(5, 0, 6, 1);

        TenantContext::set($tenant);
        $zona = $this->zonaConCuadro(0, 0, 1, 1, ['nombre' => 'Editable']);
        (new CalcularInterseccionZona)->handle($zona);
        $this->assertSame(1, DB::table('zona_seccion')->where('zona_id', $zona->id)->count());

        $this->actingAs($user)->withSession(['tenant_id' => $tenant->id])
            ->put("/api/zonas/{$zona->id}", [
                'geom' => [
                    'type' => 'Polygon',
                    'coordinates' => [[[5, 0], [5, 1], [6, 1], [6, 0], [5, 0]]],
                ],
            ])->assertSessionHasNoErrors();

        TenantContext::set($tenant);
        $filas = DB::table('zona_seccion')->where('zona_id', $zona->id)->get();
        $this->assertCount(1, $filas);
        $this->assertSame($seccionB->id, $filas->first()->seccion_id);
    }

    private function coalicionSigamos(): Coalicion
    {
        return Coalicion::factory()->create([
            'nombre' => 'Sigamos Haciendo Historia',
            'anio' => 2024,
            'partidos' => ['MORENA', 'PVEM'],
        ]);
    }
}
