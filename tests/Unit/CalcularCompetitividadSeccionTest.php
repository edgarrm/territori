<?php

namespace Tests\Unit;

use App\Actions\Estadisticas\CalcularCompetitividadSeccion;
use App\Models\Coalicion;
use Illuminate\Support\Collection;
use PHPUnit\Framework\TestCase;

class CalcularCompetitividadSeccionTest extends TestCase
{
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

    private function categoriasConUmbralFranca(int $umbral): array
    {
        $categorias = self::CATEGORIAS_DEFAULT;
        $categorias[0]['umbral'] = $umbral;

        return $categorias;
    }

    public function test_ganada_franca_cuando_diferencia_alcanza_el_umbral(): void
    {
        $votos = ['MORENA' => 100, 'PVEM' => 56, 'MC' => 79];

        $resultado = (new CalcularCompetitividadSeccion)->handle(
            $votos, 'MORENA', $this->coaliciones2024(), self::CATEGORIAS_DEFAULT, 'votos', null,
        );

        $this->assertSame('ganada-franca', $resultado['estatus_slug']);
        $this->assertSame('Ganada franca', $resultado['estatus_label']);
        $this->assertSame('#15803d', $resultado['estatus_color']);
        $this->assertSame(77, $resultado['diferencia_votos']);
        $this->assertNull($resultado['diferencia_pct']);
        $this->assertSame(156, $resultado['votos_bloque_propio']);
        $this->assertSame(79, $resultado['votos_mejor_rival']);
        $this->assertSame('Sigamos Haciendo Historia', $resultado['bloque_propio']);
    }

    public function test_competida_empatada_y_perdida(): void
    {
        $competida = (new CalcularCompetitividadSeccion)->handle(
            ['MORENA' => 57, 'MC' => 48], 'MORENA', $this->coaliciones2024(), self::CATEGORIAS_DEFAULT, 'votos', null,
        );
        $this->assertSame('competida', $competida['estatus_slug']);
        $this->assertSame(9, $competida['diferencia_votos']);

        $empatada = (new CalcularCompetitividadSeccion)->handle(
            ['MORENA' => 50, 'MC' => 50], 'MORENA', $this->coaliciones2024(), self::CATEGORIAS_DEFAULT, 'votos', null,
        );
        $this->assertSame('empatada', $empatada['estatus_slug']);
        $this->assertSame(0, $empatada['diferencia_votos']);

        $perdida = (new CalcularCompetitividadSeccion)->handle(
            ['MORENA' => 260, 'MC' => 318], 'MORENA', $this->coaliciones2024(), self::CATEGORIAS_DEFAULT, 'votos', null,
        );
        $this->assertSame('perdida', $perdida['estatus_slug']);
        $this->assertSame(-58, $perdida['diferencia_votos']);
    }

    public function test_el_umbral_del_tenant_manda_sobre_el_default(): void
    {
        $resultado = (new CalcularCompetitividadSeccion)->handle(
            ['MORENA' => 100, 'MC' => 55], 'MORENA', $this->coaliciones2024(),
            $this->categoriasConUmbralFranca(50), 'votos', null,
        );

        // Diferencia +45 con umbral 50 no alcanza GanadaFranca.
        $this->assertSame('competida', $resultado['estatus_slug']);
        $this->assertSame(45, $resultado['diferencia_votos']);
    }

    public function test_partido_sin_coalicion_2024_solo_cuenta_sus_propios_votos(): void
    {
        $resultado = (new CalcularCompetitividadSeccion)->handle(
            ['MC' => 40, 'MORENA' => 30, 'PVEM' => 20], 'MC', $this->coaliciones2024(),
            self::CATEGORIAS_DEFAULT, 'votos', null,
        );

        $this->assertSame('MC', $resultado['bloque_propio']);
        $this->assertSame(40, $resultado['votos_bloque_propio']);
        // Mejor rival es el bloque coaligado Morena-PVEM (50), no MORENA solo (30).
        $this->assertSame(50, $resultado['votos_mejor_rival']);
        $this->assertSame(-10, $resultado['diferencia_votos']);
    }

    public function test_sin_partido_configurado_devuelve_sin_datos(): void
    {
        $resultado = (new CalcularCompetitividadSeccion)->handle(
            ['MORENA' => 100, 'MC' => 50], null, $this->coaliciones2024(), self::CATEGORIAS_DEFAULT, 'votos', null,
        );

        $this->assertSame('sin_datos', $resultado['estatus_slug']);
        $this->assertNull($resultado['diferencia_votos']);
        $this->assertNull($resultado['votos_bloque_propio']);
        $this->assertNull($resultado['votos_mejor_rival']);
        $this->assertNull($resultado['bloque_propio']);
    }

    public function test_sin_votos_partidos_devuelve_sin_datos(): void
    {
        $vacio = (new CalcularCompetitividadSeccion)->handle(
            [], 'MORENA', $this->coaliciones2024(), self::CATEGORIAS_DEFAULT, 'votos', null,
        );
        $this->assertSame('sin_datos', $vacio['estatus_slug']);

        $nulo = (new CalcularCompetitividadSeccion)->handle(
            null, 'MORENA', $this->coaliciones2024(), self::CATEGORIAS_DEFAULT, 'votos', null,
        );
        $this->assertSame('sin_datos', $nulo['estatus_slug']);
        $this->assertNull($nulo['diferencia_votos']);
    }

    public function test_lista_de_una_sola_categoria_siempre_matchea_catch_all(): void
    {
        $categorias = [['nombre' => 'Todas', 'color' => '#000000', 'umbral' => null, 'slug' => 'todas']];

        $resultado = (new CalcularCompetitividadSeccion)->handle(
            ['MORENA' => 260, 'MC' => 318], 'MORENA', $this->coaliciones2024(), $categorias, 'votos', null,
        );

        $this->assertSame('todas', $resultado['estatus_slug']);
        $this->assertSame(-58, $resultado['diferencia_votos']);
    }

    public function test_lista_custom_de_seis_categorias_evalua_top_down(): void
    {
        $categorias = [
            ['nombre' => 'Arrasadora', 'color' => '#065f46', 'umbral' => 100, 'slug' => 'arrasadora'],
            ['nombre' => 'Franca', 'color' => '#15803d', 'umbral' => 30, 'slug' => 'franca'],
            ['nombre' => 'Clara', 'color' => '#84cc16', 'umbral' => 10, 'slug' => 'clara'],
            ['nombre' => 'Competida', 'color' => '#f59e0b', 'umbral' => 1, 'slug' => 'competida'],
            ['nombre' => 'Empatada', 'color' => '#6b7280', 'umbral' => 0, 'slug' => 'empatada'],
            ['nombre' => 'Perdida', 'color' => '#dc2626', 'umbral' => null, 'slug' => 'perdida'],
        ];

        $clara = (new CalcularCompetitividadSeccion)->handle(
            ['MORENA' => 100, 'MC' => 85], 'MORENA', $this->coaliciones2024(), $categorias, 'votos', null,
        );
        $this->assertSame('clara', $clara['estatus_slug']);
        $this->assertSame(15, $clara['diferencia_votos']);

        $arrasadora = (new CalcularCompetitividadSeccion)->handle(
            ['MORENA' => 300, 'MC' => 190], 'MORENA', $this->coaliciones2024(), $categorias, 'votos', null,
        );
        $this->assertSame('arrasadora', $arrasadora['estatus_slug']);
    }

    public function test_modo_porcentaje_calcula_diferencia_sobre_total_de_votos_emitidos(): void
    {
        // votos_bloque_propio=156, mejor_rival=79, diferencia=77, total=300 => 25.67pp
        $categorias = [
            ['nombre' => 'Ganada franca', 'color' => '#15803d', 'umbral' => 20.0, 'slug' => 'ganada-franca'],
            ['nombre' => 'Perdida', 'color' => '#dc2626', 'umbral' => null, 'slug' => 'perdida'],
        ];

        $resultado = (new CalcularCompetitividadSeccion)->handle(
            ['MORENA' => 100, 'PVEM' => 56, 'MC' => 79], 'MORENA', $this->coaliciones2024(),
            $categorias, 'porcentaje', 300,
        );

        $this->assertSame('ganada-franca', $resultado['estatus_slug']);
        $this->assertSame(77, $resultado['diferencia_votos']);
        $this->assertEquals(25.67, $resultado['diferencia_pct']);
    }

    public function test_modo_porcentaje_sin_total_de_votos_devuelve_sin_datos(): void
    {
        $sinTotal = (new CalcularCompetitividadSeccion)->handle(
            ['MORENA' => 100, 'MC' => 50], 'MORENA', $this->coaliciones2024(), self::CATEGORIAS_DEFAULT, 'porcentaje', null,
        );
        $this->assertSame('sin_datos', $sinTotal['estatus_slug']);

        $totalCero = (new CalcularCompetitividadSeccion)->handle(
            ['MORENA' => 100, 'MC' => 50], 'MORENA', $this->coaliciones2024(), self::CATEGORIAS_DEFAULT, 'porcentaje', 0,
        );
        $this->assertSame('sin_datos', $totalCero['estatus_slug']);
    }
}
