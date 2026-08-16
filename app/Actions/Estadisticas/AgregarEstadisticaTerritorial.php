<?php

namespace App\Actions\Estadisticas;

use App\Models\Coalicion;
use App\Models\EstadisticaSeccion;
use App\Models\Partido;
use Illuminate\Support\Collection;

/**
 * Agrega estadística electoral 2024 (F5) de un conjunto de secciones —
 * municipio, estado, o cualquier otro agrupador futuro como zona (F7)—.
 * Recibe solo IDs de sección y la configuración de competitividad ya
 * resuelta por el caller (partido/coaliciones/categorías/modo), sin
 * depender de request/controlador, para poder reusarse tal cual en otros
 * niveles.
 */
class AgregarEstadisticaTerritorial
{
    /**
     * @var array{total_votos: null, lista_nominal_2024: null, participacion_pct: null, indice_oportunidad: null, grupos_edad: null, competitividad: null, desglose: null, secciones_incluidas: int}
     */
    private const SIN_DATOS = [
        'total_votos' => null,
        'lista_nominal_2024' => null,
        'participacion_pct' => null,
        'indice_oportunidad' => null,
        'grupos_edad' => null,
        'competitividad' => null,
        'desglose' => null,
        'secciones_incluidas' => 0,
    ];

    /**
     * @param  Collection<int, int>  $seccionIds
     * @param  Collection<int, Coalicion>  $coaliciones  coaliciones del año a considerar
     * @param  non-empty-list<array{nombre: string, color: string, umbral: int|float|null, slug: string}>  $categorias
     * @param  'votos'|'porcentaje'  $modoCalculo
     * @param  Collection<int, Partido>  $partidos  catálogo completo de partidos (color, para el desglose)
     * @return array{total_votos: int|null, lista_nominal_2024: int|null, participacion_pct: float|null, indice_oportunidad: float|null, grupos_edad: array<string, array{ln: int, votos: int, participacion: float, abstencion: float, potencial: int}>|null, competitividad: array{estatus_slug: string, estatus_label: string, estatus_color: string, diferencia_votos: int|null, diferencia_pct: float|null, votos_bloque_propio: int|null, votos_mejor_rival: int|null, bloque_propio: string|null}|null, desglose: array{total_votos: int|null, votos_nulos?: int, bloques: list<array{nombre: string, siglas: list<string>, color: string, total: int, es_bloque_propio: bool, opciones: list<array{clave: string, siglas: list<string>, votos: int}>}>}|null, secciones_incluidas: int}
     */
    public function handle(
        Collection $seccionIds,
        ?string $partidoSiglas,
        Collection $coaliciones,
        array $categorias,
        string $modoCalculo,
        Collection $partidos,
    ): array {
        $estadisticas = EstadisticaSeccion::query()->whereIn('seccion_id', $seccionIds)->get();

        if ($estadisticas->isEmpty()) {
            return self::SIN_DATOS;
        }

        $totalVotos = (int) $estadisticas->sum('total_votos');
        $listaNominal = (int) $estadisticas->sum('lista_nominal_2024');
        $participacionPct = $listaNominal > 0 ? round($totalVotos / $listaNominal * 100, 2) : 0.0;
        $votosPartidos = $this->sumarVotosPartidos($estadisticas);

        return [
            'total_votos' => $totalVotos,
            'lista_nominal_2024' => $listaNominal,
            'participacion_pct' => $participacionPct,
            'indice_oportunidad' => $this->indiceOportunidadPonderado($estadisticas),
            'grupos_edad' => $this->agregarGruposEdad($estadisticas),
            'competitividad' => (new CalcularCompetitividadSeccion)->handle(
                $votosPartidos,
                $partidoSiglas,
                $coaliciones,
                $categorias,
                $modoCalculo,
                $totalVotos,
            ),
            'desglose' => (new DesglosarVotosSeccion)->handle(
                $votosPartidos,
                $totalVotos,
                $partidoSiglas,
                $coaliciones,
                $partidos,
            ),
            'secciones_incluidas' => $estadisticas->count(),
        ];
    }

    /**
     * Suma `votos_partidos` de todas las secciones, opción de boleta a
     * opción de boleta.
     *
     * @param  Collection<int, EstadisticaSeccion>  $estadisticas
     * @return array<string, int>|null
     */
    private function sumarVotosPartidos(Collection $estadisticas): ?array
    {
        $sumas = [];

        foreach ($estadisticas as $estadistica) {
            foreach ($estadistica->votos_partidos ?? [] as $opcion => $votos) {
                $sumas[$opcion] = ($sumas[$opcion] ?? 0) + (int) $votos;
            }
        }

        return $sumas === [] ? null : $sumas;
    }

    /**
     * Promedio ponderado por `lista_nominal_2024`: secciones sin índice
     * (demografía no importada) se excluyen del promedio en vez de contar
     * como 0, que sesgaría igual que un promedio simple.
     *
     * @param  Collection<int, EstadisticaSeccion>  $estadisticas
     */
    private function indiceOportunidadPonderado(Collection $estadisticas): ?float
    {
        $numerador = 0.0;
        $denominador = 0;

        foreach ($estadisticas as $estadistica) {
            if ($estadistica->indice_oportunidad === null) {
                continue;
            }

            $peso = (int) ($estadistica->lista_nominal_2024 ?? 0);
            $numerador += (float) $estadistica->indice_oportunidad * $peso;
            $denominador += $peso;
        }

        return $denominador > 0 ? round($numerador / $denominador, 2) : null;
    }

    /**
     * Suma `ln`/`votos`/`potencial` de cada bucket de edad y recalcula
     * `participacion`/`abstencion` desde los totales sumados (mismo
     * criterio que `participacion_pct`, nunca promedio de los % por sección).
     *
     * @param  Collection<int, EstadisticaSeccion>  $estadisticas
     * @return array<string, array{ln: int, votos: int, participacion: float, abstencion: float, potencial: int}>|null
     */
    private function agregarGruposEdad(Collection $estadisticas): ?array
    {
        $sumas = [];

        foreach ($estadisticas as $estadistica) {
            foreach ($estadistica->grupos_edad ?? [] as $bucket => $datos) {
                $sumas[$bucket] ??= ['ln' => 0, 'votos' => 0, 'potencial' => 0];
                $sumas[$bucket]['ln'] += (int) $datos['ln'];
                $sumas[$bucket]['votos'] += (int) $datos['votos'];
                $sumas[$bucket]['potencial'] += (int) $datos['potencial'];
            }
        }

        if ($sumas === []) {
            return null;
        }

        foreach ($sumas as $bucket => $datos) {
            $ln = $datos['ln'];
            $sumas[$bucket]['participacion'] = $ln > 0 ? round($datos['votos'] / $ln * 100, 2) : 0.0;
            $sumas[$bucket]['abstencion'] = $ln > 0 ? round(($ln - $datos['votos']) / $ln * 100, 2) : 0.0;
        }

        return $sumas;
    }
}
