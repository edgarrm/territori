<?php

namespace App\Actions\Estadisticas;

use App\Models\Coalicion;
use App\Support\Bloques;
use Illuminate\Support\Collection;

/**
 * Estatus de competitividad de una sección desde la perspectiva del partido
 * de la campaña (F2, análisis electoral por partido): bloque propio contra
 * el mejor rival, usando el servicio de bloques de F1.
 *
 * Las categorías (nombre/color/umbral) son configurables por tenant
 * (`Tenant::configuracion()['categorias_competitividad']`), ordenadas de
 * mayor a menor `umbral`; la última es un catch-all (siempre matchea).
 */
class CalcularCompetitividadSeccion
{
    private const SIN_DATOS = [
        'estatus_slug' => 'sin_datos',
        'estatus_label' => 'Sin datos',
        'estatus_color' => '#9ca3af',
        'diferencia_votos' => null,
        'diferencia_pct' => null,
        'votos_bloque_propio' => null,
        'votos_mejor_rival' => null,
        'bloque_propio' => null,
    ];

    /**
     * @param  array<string, int>|null  $votosPartidos  opción de boleta => votos
     * @param  Collection<int, Coalicion>  $coaliciones  coaliciones del año a considerar
     * @param  list<array{nombre: string, color: string, umbral: int|float|null, slug?: string}>  $categorias  ordenada desc por umbral; la última es catch-all
     * @param  'votos'|'porcentaje'  $modoCalculo
     * @param  int|null  $totalVotosSeccion  total de votos emitidos en la sección; requerido en modo 'porcentaje'
     * @return array{estatus_slug: string, estatus_label: string, estatus_color: string, diferencia_votos: int|null, diferencia_pct: float|null, votos_bloque_propio: int|null, votos_mejor_rival: int|null, bloque_propio: string|null}
     */
    public function handle(
        ?array $votosPartidos,
        ?string $partidoSiglas,
        Collection $coaliciones,
        array $categorias,
        string $modoCalculo,
        ?int $totalVotosSeccion,
    ): array {
        if ($partidoSiglas === null || $votosPartidos === null || $votosPartidos === []) {
            return self::SIN_DATOS;
        }

        if ($modoCalculo === 'porcentaje' && ! $totalVotosSeccion) {
            return self::SIN_DATOS;
        }

        $bloques = new Bloques;
        $agrupados = collect($bloques->agrupar($coaliciones, $votosPartidos));
        $nombrePropio = $bloques->bloqueDePartido($coaliciones, $partidoSiglas)['nombre'];

        $votosPropio = $agrupados->firstWhere('nombre', $nombrePropio)['votos'] ?? 0;
        $votosMejorRival = $agrupados
            ->reject(fn (array $bloque): bool => $bloque['nombre'] === $nombrePropio)
            ->max('votos') ?? 0;

        $diferenciaVotos = $votosPropio - $votosMejorRival;
        $diferenciaPct = $modoCalculo === 'porcentaje'
            ? round($diferenciaVotos / $totalVotosSeccion * 100, 2)
            : null;

        $valorComparado = $modoCalculo === 'porcentaje' ? $diferenciaPct : $diferenciaVotos;
        $categoria = $this->resolverCategoria($categorias, $valorComparado);

        return [
            'estatus_slug' => $categoria['slug'],
            'estatus_label' => $categoria['nombre'],
            'estatus_color' => $categoria['color'],
            'diferencia_votos' => $diferenciaVotos,
            'diferencia_pct' => $diferenciaPct,
            'votos_bloque_propio' => $votosPropio,
            'votos_mejor_rival' => $votosMejorRival,
            'bloque_propio' => $nombrePropio,
        ];
    }

    /**
     * Evalúa la lista de categorías de mayor a menor umbral (top-down): la
     * primera cuyo umbral alcance `$valor` gana; la última de la lista es
     * catch-all (siempre matchea, sin importar su `umbral`).
     *
     * @param  list<array{nombre: string, color: string, umbral: int|float|null, slug?: string}>  $categorias
     * @return array{nombre: string, color: string, umbral: int|float|null, slug: string}
     */
    private function resolverCategoria(array $categorias, int|float $valor): array
    {
        $ultimo = array_key_last($categorias);

        foreach ($categorias as $indice => $categoria) {
            if ($indice === $ultimo || ($categoria['umbral'] !== null && $valor >= $categoria['umbral'])) {
                return $categoria;
            }
        }

        return $categorias[$ultimo];
    }
}
