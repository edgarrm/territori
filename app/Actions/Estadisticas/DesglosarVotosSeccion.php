<?php

namespace App\Actions\Estadisticas;

use App\Models\Coalicion;
use App\Models\Partido;
use App\Support\Bloques;
use Illuminate\Support\Collection;

/**
 * Desglose de votos 2024 por bloque y opción de boleta para el detalle de
 * sección (F3): agrupa con el servicio de bloques de F1 y completa el
 * catálogo con las opciones en 0 votos que `votos_partidos` omite
 * (`ImportarEstadisticasCommand` solo persiste valores > 0).
 */
class DesglosarVotosSeccion
{
    private const COLOR_INDEPENDIENTES = '#6b7280';

    /**
     * Universo de opciones de boleta 2024 (Mazatlán), mismo listado que
     * `App\Console\Commands\ImportarEstadisticasCommand::COLUMNAS_PARTIDOS`.
     *
     * @var list<string>
     */
    public const OPCIONES_BOLETA_2024 = [
        'PAN', 'PRI', 'PRD', 'PT', 'PVEM', 'MC', 'PAS', 'MORENA', 'PES',
        'PVEM_MORENA', 'PAN_PRI_PRD_PAS', 'PAN_PRI_PRD', 'PAN_PRI_PAS', 'PAN_PRD_PAS',
        'PRI_PRD_PAS', 'PAN_PRI', 'PAN_PRD', 'PAN_PAS', 'PRI_PRD', 'PRI_PAS', 'PRD_PAS',
        'CAND_IND1', 'CAND_IND2', 'CAND_IND3',
    ];

    /**
     * @param  array<string, int>|null  $votosPartidos  opción de boleta => votos (solo valores > 0, tal cual persiste el import)
     * @param  Collection<int, Coalicion>  $coaliciones  coaliciones del año a considerar
     * @param  Collection<int, Partido>  $partidos  catálogo completo de partidos (color)
     * @return array{total_votos: int|null, votos_nulos?: int, bloques: list<array{nombre: string, siglas: list<string>, color: string, total: int, es_bloque_propio: bool, opciones: list<array{clave: string, siglas: list<string>, votos: int}>}>}|null
     */
    public function handle(
        ?array $votosPartidos,
        ?int $totalVotos,
        ?string $partidoSiglas,
        Collection $coaliciones,
        Collection $partidos,
    ): ?array {
        if ($votosPartidos === null || $votosPartidos === []) {
            return null;
        }

        $votosCompletos = array_merge(
            array_fill_keys(self::OPCIONES_BOLETA_2024, 0),
            $votosPartidos,
        );

        $bloquesUtil = new Bloques;
        $agrupados = $bloquesUtil->agrupar($coaliciones, $votosCompletos);
        $nombrePropio = $partidoSiglas !== null
            ? $bloquesUtil->bloqueDePartido($coaliciones, $partidoSiglas)['nombre']
            : null;

        $partidosPorSiglas = $partidos->keyBy('siglas');

        $bloques = collect($agrupados)
            ->map(function (array $bloque) use ($nombrePropio, $partidosPorSiglas): array {
                $opciones = array_values(collect($bloque['opciones'])
                    ->map(fn (array $opcion): array => [
                        'clave' => $opcion['opcion'],
                        'siglas' => $this->siglasDeOpcion($opcion['opcion']),
                        'votos' => $opcion['votos'],
                    ])
                    ->sortByDesc('votos')
                    ->all());

                return [
                    'nombre' => $bloque['nombre'],
                    'siglas' => $bloque['partidos'],
                    'color' => $this->colorDeBloque($bloque['partidos'], $partidosPorSiglas),
                    'total' => $bloque['votos'],
                    'es_bloque_propio' => $nombrePropio !== null && $bloque['nombre'] === $nombrePropio,
                    'opciones' => $opciones,
                ];
            });

        $bloques = array_values($bloques->sortByDesc('total')->all());

        $resultado = ['total_votos' => $totalVotos];

        if ($totalVotos !== null) {
            $resultado['votos_nulos'] = $totalVotos - array_sum($votosCompletos);
        }

        $resultado['bloques'] = $bloques;

        return $resultado;
    }

    /**
     * @return list<string>
     */
    private function siglasDeOpcion(string $opcion): array
    {
        return str_starts_with($opcion, 'CAND_IND') ? [] : explode('_', $opcion);
    }

    /**
     * @param  list<string>  $siglasBloque
     * @param  Collection<string, Partido>  $partidosPorSiglas
     */
    private function colorDeBloque(array $siglasBloque, Collection $partidosPorSiglas): string
    {
        foreach ($siglasBloque as $siglas) {
            $partido = $partidosPorSiglas->get($siglas);

            if ($partido !== null) {
                return $partido->color;
            }
        }

        return self::COLOR_INDEPENDIENTES;
    }
}
