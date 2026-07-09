<?php

namespace Tests\Unit;

use App\Models\Coalicion;
use App\Support\Bloques;
use Illuminate\Support\Collection;
use PHPUnit\Framework\TestCase;

class BloquesTest extends TestCase
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

    public function test_agrupar_clasifica_opciones_en_sus_bloques(): void
    {
        $votosPartidos = [
            'PAN_PRI' => 40,
            'PAN_PRI_PRD_PAS' => 20,
            'PVEM_MORENA' => 50,
            'MORENA' => 10,
            'MC' => 15,
            'CAND_IND1' => 1,
        ];

        $bloques = (new Bloques)->agrupar($this->coaliciones2024(), $votosPartidos);

        $porNombre = collect($bloques)->keyBy('nombre');

        $this->assertSame(60, $porNombre['Fuerza y Corazón por México']['votos']);
        $this->assertSame(60, $porNombre['Sigamos Haciendo Historia']['votos']);
        $this->assertSame(15, $porNombre['MC']['votos']);
        $this->assertSame(1, $porNombre['Independientes']['votos']);

        $sumaBloques = collect($bloques)->sum('votos');
        $sumaOpciones = array_sum($votosPartidos);
        $this->assertSame($sumaOpciones, $sumaBloques);
    }

    public function test_bloque_de_partido_coaligado(): void
    {
        $bloque = (new Bloques)->bloqueDePartido($this->coaliciones2024(), 'MORENA');

        $this->assertSame('Sigamos Haciendo Historia', $bloque['nombre']);
        $this->assertEqualsCanonicalizing(['MORENA', 'PVEM'], $bloque['partidos']);
    }

    public function test_bloque_de_partido_no_coaligado(): void
    {
        $bloque = (new Bloques)->bloqueDePartido($this->coaliciones2024(), 'MC');

        $this->assertSame('MC', $bloque['nombre']);
        $this->assertSame(['MC'], $bloque['partidos']);
    }
}
