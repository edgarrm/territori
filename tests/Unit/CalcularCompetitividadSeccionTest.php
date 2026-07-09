<?php

namespace Tests\Unit;

use App\Actions\Estadisticas\CalcularCompetitividadSeccion;
use App\Enums\CompetitividadSeccion;
use App\Models\Coalicion;
use Illuminate\Support\Collection;
use PHPUnit\Framework\TestCase;

class CalcularCompetitividadSeccionTest extends TestCase
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

    public function test_ganada_franca_cuando_diferencia_alcanza_el_umbral(): void
    {
        $votos = ['MORENA' => 100, 'PVEM' => 56, 'MC' => 79];

        $resultado = (new CalcularCompetitividadSeccion)->handle($votos, 'MORENA', $this->coaliciones2024(), 30);

        $this->assertSame(CompetitividadSeccion::GanadaFranca, $resultado['estatus']);
        $this->assertSame(77, $resultado['diferencia_votos']);
        $this->assertSame(156, $resultado['votos_bloque_propio']);
        $this->assertSame(79, $resultado['votos_mejor_rival']);
        $this->assertSame('Sigamos Haciendo Historia', $resultado['bloque_propio']);
    }

    public function test_competida_empatada_y_perdida(): void
    {
        $competida = (new CalcularCompetitividadSeccion)->handle(
            ['MORENA' => 57, 'MC' => 48], 'MORENA', $this->coaliciones2024(), 30
        );
        $this->assertSame(CompetitividadSeccion::Competida, $competida['estatus']);
        $this->assertSame(9, $competida['diferencia_votos']);

        $empatada = (new CalcularCompetitividadSeccion)->handle(
            ['MORENA' => 50, 'MC' => 50], 'MORENA', $this->coaliciones2024(), 30
        );
        $this->assertSame(CompetitividadSeccion::Empatada, $empatada['estatus']);
        $this->assertSame(0, $empatada['diferencia_votos']);

        $perdida = (new CalcularCompetitividadSeccion)->handle(
            ['MORENA' => 260, 'MC' => 318], 'MORENA', $this->coaliciones2024(), 30
        );
        $this->assertSame(CompetitividadSeccion::Perdida, $perdida['estatus']);
        $this->assertSame(-58, $perdida['diferencia_votos']);
    }

    public function test_el_umbral_del_tenant_manda_sobre_el_default(): void
    {
        $resultado = (new CalcularCompetitividadSeccion)->handle(
            ['MORENA' => 100, 'MC' => 55], 'MORENA', $this->coaliciones2024(), 50
        );

        // Diferencia +45 con umbral 50 no alcanza GanadaFranca.
        $this->assertSame(CompetitividadSeccion::Competida, $resultado['estatus']);
        $this->assertSame(45, $resultado['diferencia_votos']);
    }

    public function test_partido_sin_coalicion_2024_solo_cuenta_sus_propios_votos(): void
    {
        $resultado = (new CalcularCompetitividadSeccion)->handle(
            ['MC' => 40, 'MORENA' => 30, 'PVEM' => 20], 'MC', $this->coaliciones2024(), 30
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
            ['MORENA' => 100, 'MC' => 50], null, $this->coaliciones2024(), 30
        );

        $this->assertSame(CompetitividadSeccion::SinDatos, $resultado['estatus']);
        $this->assertNull($resultado['diferencia_votos']);
        $this->assertNull($resultado['votos_bloque_propio']);
        $this->assertNull($resultado['votos_mejor_rival']);
        $this->assertNull($resultado['bloque_propio']);
    }

    public function test_sin_votos_partidos_devuelve_sin_datos(): void
    {
        $vacio = (new CalcularCompetitividadSeccion)->handle([], 'MORENA', $this->coaliciones2024(), 30);
        $this->assertSame(CompetitividadSeccion::SinDatos, $vacio['estatus']);

        $nulo = (new CalcularCompetitividadSeccion)->handle(null, 'MORENA', $this->coaliciones2024(), 30);
        $this->assertSame(CompetitividadSeccion::SinDatos, $nulo['estatus']);
        $this->assertNull($nulo['diferencia_votos']);
    }
}
