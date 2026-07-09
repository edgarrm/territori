<?php

namespace Tests\Unit;

use App\Actions\Estadisticas\DesglosarVotosSeccion;
use App\Models\Coalicion;
use App\Models\Partido;
use Illuminate\Support\Collection;
use PHPUnit\Framework\TestCase;

class DesglosarVotosSeccionTest extends TestCase
{
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
     * @return Collection<int, Partido>
     */
    private function partidos(): Collection
    {
        return collect([
            new Partido(['siglas' => 'PAN', 'nombre' => 'PAN', 'color' => '#0D5EAF']),
            new Partido(['siglas' => 'PRI', 'nombre' => 'PRI', 'color' => '#C4161C']),
            new Partido(['siglas' => 'PRD', 'nombre' => 'PRD', 'color' => '#FFCC00']),
            new Partido(['siglas' => 'PT', 'nombre' => 'PT', 'color' => '#D52B1E']),
            new Partido(['siglas' => 'PVEM', 'nombre' => 'PVEM', 'color' => '#4C9F38']),
            new Partido(['siglas' => 'MC', 'nombre' => 'MC', 'color' => '#F58025']),
            new Partido(['siglas' => 'PAS', 'nombre' => 'PAS', 'color' => '#7B3F99']),
            new Partido(['siglas' => 'MORENA', 'nombre' => 'MORENA', 'color' => '#8B1538']),
            new Partido(['siglas' => 'PES', 'nombre' => 'PES', 'color' => '#4B0082']),
        ]);
    }

    /** Fixture real: sección 2540 de Mazatlán, CSV 2024 (solo valores > 0 persistidos). */
    private function votosSeccion2540(): array
    {
        return ['PAN' => 30, 'PRI' => 6, 'PT' => 3, 'MC' => 8, 'PAS' => 5, 'MORENA' => 57, 'PAN_PRI' => 1, 'PAN_PRI_PRD' => 3];
    }

    public function test_agrupa_por_bloque_con_catalogo_completo_de_opciones(): void
    {
        $resultado = (new DesglosarVotosSeccion)->handle(
            $this->votosSeccion2540(), 116, 'MORENA', $this->coaliciones2024(), $this->partidos(),
        );

        $porNombre = collect($resultado['bloques'])->keyBy('nombre');

        $this->assertSame(45, $porNombre['Fuerza y Corazón por México']['total']);
        $this->assertSame(57, $porNombre['Sigamos Haciendo Historia']['total']);
        $this->assertSame(3, $porNombre['PT']['total']);
        $this->assertSame(8, $porNombre['MC']['total']);
    }

    public function test_incluye_opciones_en_cero_dentro_del_bloque(): void
    {
        $resultado = (new DesglosarVotosSeccion)->handle(
            $this->votosSeccion2540(), 116, 'MORENA', $this->coaliciones2024(), $this->partidos(),
        );

        $sigamos = collect($resultado['bloques'])->firstWhere('nombre', 'Sigamos Haciendo Historia');
        $opciones = collect($sigamos['opciones'])->keyBy('clave');

        $this->assertSame(57, $opciones['MORENA']['votos']);
        $this->assertSame(0, $opciones['PVEM']['votos']);
        $this->assertSame(0, $opciones['PVEM_MORENA']['votos']);
        $this->assertSame(['PVEM', 'MORENA'], $opciones['PVEM_MORENA']['siglas']);
    }

    public function test_votos_nulos_se_derivan_de_total_votos(): void
    {
        $resultado = (new DesglosarVotosSeccion)->handle(
            $this->votosSeccion2540(), 116, 'MORENA', $this->coaliciones2024(), $this->partidos(),
        );

        // Suma de las 24 opciones del catálogo (con ceros) = suma del jsonb = 113.
        $this->assertSame(116 - 113, $resultado['votos_nulos']);
        $this->assertSame(116, $resultado['total_votos']);
    }

    public function test_es_bloque_propio_marca_solo_el_bloque_del_partido_del_tenant(): void
    {
        $resultado = (new DesglosarVotosSeccion)->handle(
            $this->votosSeccion2540(), 116, 'MORENA', $this->coaliciones2024(), $this->partidos(),
        );

        $porNombre = collect($resultado['bloques'])->keyBy('nombre');

        $this->assertTrue($porNombre['Sigamos Haciendo Historia']['es_bloque_propio']);
        $this->assertFalse($porNombre['Fuerza y Corazón por México']['es_bloque_propio']);
        $this->assertFalse($porNombre['PT']['es_bloque_propio']);
    }

    public function test_sin_partido_configurado_ningun_bloque_es_propio(): void
    {
        $resultado = (new DesglosarVotosSeccion)->handle(
            $this->votosSeccion2540(), 116, null, $this->coaliciones2024(), $this->partidos(),
        );

        $this->assertTrue(collect($resultado['bloques'])->every(fn (array $b): bool => $b['es_bloque_propio'] === false));
    }

    public function test_bloques_ordenados_por_total_desc(): void
    {
        $resultado = (new DesglosarVotosSeccion)->handle(
            $this->votosSeccion2540(), 116, 'MORENA', $this->coaliciones2024(), $this->partidos(),
        );

        $totales = collect($resultado['bloques'])->pluck('total')->all();
        $ordenados = $totales;
        rsort($ordenados);

        $this->assertSame($ordenados, $totales);
    }

    public function test_opciones_dentro_de_un_bloque_ordenadas_por_votos_desc(): void
    {
        $resultado = (new DesglosarVotosSeccion)->handle(
            $this->votosSeccion2540(), 116, 'MORENA', $this->coaliciones2024(), $this->partidos(),
        );

        $fuerza = collect($resultado['bloques'])->firstWhere('nombre', 'Fuerza y Corazón por México');
        $votos = collect($fuerza['opciones'])->pluck('votos')->all();
        $ordenados = $votos;
        rsort($ordenados);

        $this->assertSame($ordenados, $votos);
    }

    public function test_particion_ninguna_opcion_del_jsonb_queda_fuera_ni_duplicada(): void
    {
        $votos = $this->votosSeccion2540();
        $resultado = (new DesglosarVotosSeccion)->handle(
            $votos, 116, 'MORENA', $this->coaliciones2024(), $this->partidos(),
        );

        $todasLasOpciones = collect($resultado['bloques'])->flatMap(fn (array $b): array => $b['opciones'])->pluck('clave');

        // Cada clave del catálogo aparece exactamente una vez en todo el desglose.
        $this->assertSame($todasLasOpciones->count(), $todasLasOpciones->unique()->count());

        foreach (array_keys($votos) as $clave) {
            $this->assertContains($clave, $todasLasOpciones);
        }
    }

    public function test_null_o_vacio_devuelve_null(): void
    {
        $this->assertNull((new DesglosarVotosSeccion)->handle(null, 116, 'MORENA', $this->coaliciones2024(), $this->partidos()));
        $this->assertNull((new DesglosarVotosSeccion)->handle([], 116, 'MORENA', $this->coaliciones2024(), $this->partidos()));
    }

    public function test_sin_total_votos_omite_la_clave_votos_nulos(): void
    {
        $resultado = (new DesglosarVotosSeccion)->handle(
            $this->votosSeccion2540(), null, 'MORENA', $this->coaliciones2024(), $this->partidos(),
        );

        $this->assertArrayNotHasKey('votos_nulos', $resultado);
        $this->assertNull($resultado['total_votos']);
    }

    public function test_color_de_bloque_usa_el_partido_dominante_e_independientes_es_gris(): void
    {
        $votos = ['CAND_IND1' => 5, 'MORENA' => 10];
        $resultado = (new DesglosarVotosSeccion)->handle($votos, 15, null, $this->coaliciones2024(), $this->partidos());

        $porNombre = collect($resultado['bloques'])->keyBy('nombre');

        $this->assertSame('#8B1538', $porNombre['Sigamos Haciendo Historia']['color']);
        $this->assertSame('#6b7280', $porNombre['Independientes']['color']);
        $this->assertSame([], collect($porNombre['Independientes']['opciones'])->firstWhere('clave', 'CAND_IND1')['siglas']);
    }
}
