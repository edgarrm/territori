<?php

namespace Tests\Feature\Territorio;

use App\Actions\Estadisticas\AgregarEstadisticaTerritorial;
use App\Actions\Estadisticas\CalcularCompetitividadSeccion;
use App\Actions\Estadisticas\DesglosarVotosSeccion;
use App\Models\Coalicion;
use App\Models\EstadisticaSeccion;
use App\Models\Municipio;
use App\Models\Partido;
use App\Models\Seccion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Tests\TestCase;

class AgregarEstadisticaTerritorialTest extends TestCase
{
    use RefreshDatabase;

    private const CATEGORIAS_DEFAULT = [
        ['nombre' => 'Ganada franca', 'color' => '#15803d', 'umbral' => 30, 'slug' => 'ganada-franca'],
        ['nombre' => 'Competida', 'color' => '#f59e0b', 'umbral' => 1, 'slug' => 'competida'],
        ['nombre' => 'Empatada', 'color' => '#6b7280', 'umbral' => 0, 'slug' => 'empatada'],
        ['nombre' => 'Perdida', 'color' => '#dc2626', 'umbral' => null, 'slug' => 'perdida'],
    ];

    /**
     * @return Collection<int, Coalicion>
     */
    private function coaliciones2024(): Collection
    {
        return collect([
            new Coalicion(['nombre' => 'Fuerza y Corazón por México', 'anio' => 2024, 'partidos' => ['PAN', 'PRI', 'PRD', 'PAS']]),
            new Coalicion(['nombre' => 'Sigamos Haciendo Historia', 'anio' => 2024, 'partidos' => ['MORENA', 'PVEM']]),
        ]);
    }

    /**
     * Criterio 1: participación agregada = sum(total_votos)/sum(lista_nominal)*100,
     * no el promedio simple de los % individuales (330/950 != promedio de
     * 33.33/37.5/32 == 34.28).
     */
    public function test_participacion_pct_se_recalcula_desde_los_totales_no_es_promedio(): void
    {
        $municipio = Municipio::factory()->create();
        $secciones = collect([
            ['total_votos' => 100, 'lista_nominal_2024' => 300],
            ['total_votos' => 150, 'lista_nominal_2024' => 400],
            ['total_votos' => 80, 'lista_nominal_2024' => 250],
        ])->map(function (array $datos) use ($municipio) {
            $seccion = Seccion::factory()->create(['municipio_id' => $municipio->id]);

            return EstadisticaSeccion::factory()->create([
                'seccion_id' => $seccion->id,
                'total_votos' => $datos['total_votos'],
                'lista_nominal_2024' => $datos['lista_nominal_2024'],
                'votos_partidos' => [],
            ]);
        });

        $resultado = (new AgregarEstadisticaTerritorial)->handle(
            $secciones->pluck('seccion_id'),
            null,
            $this->coaliciones2024(),
            self::CATEGORIAS_DEFAULT,
            'votos',
            Partido::all(),
        );

        $this->assertSame(330, $resultado['total_votos']);
        $this->assertSame(950, $resultado['lista_nominal_2024']);
        $this->assertEquals(round(330 / 950 * 100, 2), $resultado['participacion_pct']);
        $this->assertNotEqualsWithDelta(
            (33.33 + 37.5 + 32) / 3,
            $resultado['participacion_pct'],
            0.01,
        );
    }

    /**
     * Criterio 2: índice de oportunidad ponderado por lista_nominal_2024:
     * (80*900 + 20*100)/1000 = 74, no 50 (promedio simple).
     */
    public function test_indice_oportunidad_es_promedio_ponderado_por_lista_nominal(): void
    {
        $municipio = Municipio::factory()->create();

        $seccionA = Seccion::factory()->create(['municipio_id' => $municipio->id]);
        $seccionB = Seccion::factory()->create(['municipio_id' => $municipio->id]);

        EstadisticaSeccion::factory()->create([
            'seccion_id' => $seccionA->id,
            'indice_oportunidad' => 80,
            'lista_nominal_2024' => 900,
            'votos_partidos' => [],
        ]);
        EstadisticaSeccion::factory()->create([
            'seccion_id' => $seccionB->id,
            'indice_oportunidad' => 20,
            'lista_nominal_2024' => 100,
            'votos_partidos' => [],
        ]);

        $resultado = (new AgregarEstadisticaTerritorial)->handle(
            collect([$seccionA->id, $seccionB->id]),
            null,
            $this->coaliciones2024(),
            self::CATEGORIAS_DEFAULT,
            'votos',
            Partido::all(),
        );

        $this->assertEquals(74.0, $resultado['indice_oportunidad']);
        $this->assertNotEquals(50.0, $resultado['indice_oportunidad']);
    }

    /**
     * Criterio 3: votos_partidos sumados alimentan CalcularCompetitividadSeccion
     * y DesglosarVotosSeccion con exactamente el mismo resultado que si esas
     * secciones fueran una sola con los votos ya sumados (equivalencia contra
     * las Actions existentes, sin duplicar su lógica).
     */
    public function test_votos_partidos_agregados_son_equivalentes_a_una_seccion_fusionada(): void
    {
        $municipio = Municipio::factory()->create();
        $seccionA = Seccion::factory()->create(['municipio_id' => $municipio->id]);
        $seccionB = Seccion::factory()->create(['municipio_id' => $municipio->id]);

        EstadisticaSeccion::factory()->create([
            'seccion_id' => $seccionA->id,
            'total_votos' => 156,
            'votos_partidos' => ['MORENA' => 50, 'PVEM' => 20, 'MC' => 40],
        ]);
        EstadisticaSeccion::factory()->create([
            'seccion_id' => $seccionB->id,
            'total_votos' => 144,
            'votos_partidos' => ['MORENA' => 50, 'PVEM' => 36, 'MC' => 39],
        ]);

        $resultado = (new AgregarEstadisticaTerritorial)->handle(
            collect([$seccionA->id, $seccionB->id]),
            'MORENA',
            $this->coaliciones2024(),
            self::CATEGORIAS_DEFAULT,
            'votos',
            Partido::all(),
        );

        $votosFusionados = ['MORENA' => 100, 'PVEM' => 56, 'MC' => 79];
        $totalFusionado = 300;

        $competitividadEsperada = (new CalcularCompetitividadSeccion)->handle(
            $votosFusionados, 'MORENA', $this->coaliciones2024(), self::CATEGORIAS_DEFAULT, 'votos', $totalFusionado,
        );
        $desgloseEsperado = (new DesglosarVotosSeccion)->handle(
            $votosFusionados, $totalFusionado, 'MORENA', $this->coaliciones2024(), Partido::all(),
        );

        $this->assertSame($competitividadEsperada, $resultado['competitividad']);
        $this->assertSame($desgloseEsperado, $resultado['desglose']);
    }

    /**
     * Criterio 4: ninguna sección tiene EstadisticaSeccion => secciones_incluidas
     * = 0 y el resto de los campos en null, sin excepción.
     */
    public function test_sin_estadisticas_devuelve_secciones_incluidas_cero_y_campos_null(): void
    {
        $municipio = Municipio::factory()->create();
        $seccion = Seccion::factory()->create(['municipio_id' => $municipio->id]);

        $resultado = (new AgregarEstadisticaTerritorial)->handle(
            collect([$seccion->id]),
            'MORENA',
            $this->coaliciones2024(),
            self::CATEGORIAS_DEFAULT,
            'votos',
            Partido::all(),
        );

        $this->assertSame(0, $resultado['secciones_incluidas']);
        $this->assertNull($resultado['total_votos']);
        $this->assertNull($resultado['lista_nominal_2024']);
        $this->assertNull($resultado['participacion_pct']);
        $this->assertNull($resultado['indice_oportunidad']);
        $this->assertNull($resultado['grupos_edad']);
        $this->assertNull($resultado['competitividad']);
        $this->assertNull($resultado['desglose']);
    }

    /**
     * Colección de secciones vacía: mismo comportamiento que "ninguna tiene
     * estadística".
     */
    public function test_seccion_ids_vacio_devuelve_secciones_incluidas_cero(): void
    {
        $resultado = (new AgregarEstadisticaTerritorial)->handle(
            collect(),
            'MORENA',
            $this->coaliciones2024(),
            self::CATEGORIAS_DEFAULT,
            'votos',
            Partido::all(),
        );

        $this->assertSame(0, $resultado['secciones_incluidas']);
        $this->assertNull($resultado['total_votos']);
    }

    /**
     * grupos_edad agregado: suma ln/votos/potencial y recalcula
     * participación/abstención desde los totales sumados.
     */
    public function test_grupos_edad_se_suma_y_recalcula_desde_los_totales(): void
    {
        $municipio = Municipio::factory()->create();
        $seccionA = Seccion::factory()->create(['municipio_id' => $municipio->id]);
        $seccionB = Seccion::factory()->create(['municipio_id' => $municipio->id]);

        EstadisticaSeccion::factory()->create([
            'seccion_id' => $seccionA->id,
            'votos_partidos' => [],
            'grupos_edad' => [
                '18-29' => ['ln' => 500, 'votos' => 150, 'participacion' => 30.0, 'abstencion' => 70.0, 'potencial' => 120],
            ],
        ]);
        EstadisticaSeccion::factory()->create([
            'seccion_id' => $seccionB->id,
            'votos_partidos' => [],
            'grupos_edad' => [
                '18-29' => ['ln' => 300, 'votos' => 210, 'participacion' => 70.0, 'abstencion' => 30.0, 'potencial' => 30],
            ],
        ]);

        $resultado = (new AgregarEstadisticaTerritorial)->handle(
            collect([$seccionA->id, $seccionB->id]),
            null,
            $this->coaliciones2024(),
            self::CATEGORIAS_DEFAULT,
            'votos',
            Partido::all(),
        );

        $bucket = $resultado['grupos_edad']['18-29'];
        $this->assertSame(800, $bucket['ln']);
        $this->assertSame(360, $bucket['votos']);
        $this->assertSame(150, $bucket['potencial']);
        $this->assertEquals(45.0, $bucket['participacion']);
        $this->assertEquals(55.0, $bucket['abstencion']);
    }
}
