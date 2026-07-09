<?php

namespace App\Actions\Estadisticas;

use App\Enums\CompetitividadSeccion;
use App\Models\Coalicion;
use App\Support\Bloques;
use Illuminate\Support\Collection;

/**
 * Estatus de competitividad de una sección desde la perspectiva del partido
 * de la campaña (F2, análisis electoral por partido): bloque propio contra
 * el mejor rival, usando el servicio de bloques de F1.
 */
class CalcularCompetitividadSeccion
{
    /**
     * @param  array<string, int>|null  $votosPartidos  opción de boleta => votos
     * @param  Collection<int, Coalicion>  $coaliciones  coaliciones del año a considerar
     * @return array{estatus: CompetitividadSeccion, diferencia_votos: int|null, votos_bloque_propio: int|null, votos_mejor_rival: int|null, bloque_propio: string|null}
     */
    public function handle(?array $votosPartidos, ?string $partidoSiglas, Collection $coaliciones, int $umbral): array
    {
        if ($partidoSiglas === null || $votosPartidos === null || $votosPartidos === []) {
            return [
                'estatus' => CompetitividadSeccion::SinDatos,
                'diferencia_votos' => null,
                'votos_bloque_propio' => null,
                'votos_mejor_rival' => null,
                'bloque_propio' => null,
            ];
        }

        $bloques = new Bloques;
        $agrupados = collect($bloques->agrupar($coaliciones, $votosPartidos));
        $nombrePropio = $bloques->bloqueDePartido($coaliciones, $partidoSiglas)['nombre'];

        $votosPropio = $agrupados->firstWhere('nombre', $nombrePropio)['votos'] ?? 0;
        $votosMejorRival = $agrupados
            ->reject(fn (array $bloque): bool => $bloque['nombre'] === $nombrePropio)
            ->max('votos') ?? 0;

        $diferencia = $votosPropio - $votosMejorRival;

        $estatus = match (true) {
            $diferencia >= $umbral => CompetitividadSeccion::GanadaFranca,
            $diferencia > 0 => CompetitividadSeccion::Competida,
            $diferencia === 0 => CompetitividadSeccion::Empatada,
            default => CompetitividadSeccion::Perdida,
        };

        return [
            'estatus' => $estatus,
            'diferencia_votos' => $diferencia,
            'votos_bloque_propio' => $votosPropio,
            'votos_mejor_rival' => $votosMejorRival,
            'bloque_propio' => $nombrePropio,
        ];
    }
}
