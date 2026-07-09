<?php

namespace App\Support;

use App\Models\Coalicion;
use Illuminate\Support\Collection;

/**
 * Agrupa opciones de boleta (`votos_partidos`) en bloques de coalición,
 * dado el catálogo de coaliciones de un año. Reutilizado por F2
 * (competitividad por partido) y F3 (desglose de votos por sección).
 */
class Bloques
{
    /**
     * @param  Collection<int, Coalicion>  $coaliciones  coaliciones del año a considerar
     * @param  array<string, int>  $votosPartidos  opción de boleta => votos
     * @return list<array{nombre: string, partidos: list<string>, votos: int, opciones: list<array{opcion: string, votos: int}>}>
     */
    public function agrupar(Collection $coaliciones, array $votosPartidos): array
    {
        $bloques = [];

        foreach ($votosPartidos as $opcion => $votos) {
            $opcion = (string) $opcion;
            $votos = (int) $votos;
            $resuelto = $this->resolverBloque($coaliciones, $opcion);
            $nombre = $resuelto['nombre'];

            $bloques[$nombre] ??= [
                'nombre' => $nombre,
                'partidos' => $resuelto['partidos'],
                'votos' => 0,
                'opciones' => [],
            ];
            $bloques[$nombre]['votos'] += $votos;
            $bloques[$nombre]['opciones'][] = ['opcion' => $opcion, 'votos' => $votos];
        }

        return array_values($bloques);
    }

    /**
     * Bloque de un partido dado: la coalición que lo contiene, o el partido
     * solo si no está coaligado ese año.
     *
     * @param  Collection<int, Coalicion>  $coaliciones
     * @return array{nombre: string, partidos: list<string>}
     */
    public function bloqueDePartido(Collection $coaliciones, string $siglas): array
    {
        $coalicion = $coaliciones->first(fn (Coalicion $c): bool => in_array($siglas, $c->partidos, true));

        if ($coalicion !== null) {
            return ['nombre' => $coalicion->nombre, 'partidos' => array_values($coalicion->partidos)];
        }

        return ['nombre' => $siglas, 'partidos' => [$siglas]];
    }

    /**
     * @param  Collection<int, Coalicion>  $coaliciones
     * @return array{nombre: string, partidos: list<string>}
     */
    private function resolverBloque(Collection $coaliciones, string $opcion): array
    {
        if (str_starts_with($opcion, 'CAND_IND')) {
            return ['nombre' => 'Independientes', 'partidos' => []];
        }

        $siglas = explode('_', $opcion);

        $coalicion = $coaliciones->first(
            fn (Coalicion $c): bool => array_diff($siglas, $c->partidos) === []
        );

        if ($coalicion !== null) {
            return ['nombre' => $coalicion->nombre, 'partidos' => array_values($coalicion->partidos)];
        }

        return ['nombre' => $opcion, 'partidos' => $siglas];
    }
}
