<?php

namespace App\Actions\Cobertura;

use App\Models\CoberturaSeccion;
use App\Models\Seccion;
use Illuminate\Support\Collection;

/**
 * Agrega la cobertura de campaña (F5) de un conjunto de secciones —
 * municipio, estado, o cualquier otro agrupador futuro como zona (F7)—.
 * Requiere `TenantContext` activo, igual que `RecalcularCoberturaSeccion`:
 * `CoberturaSeccion` ya está tenant-scoped por `BelongsToTenant`, así que no
 * hay que filtrar `tenant_id` a mano.
 */
class AgregarCoberturaTerritorial
{
    /**
     * @param  Collection<int, int>  $seccionIds
     * @return array{capturados: int, meta: int, verificados: int, cobertura: float, movilizacion_verificada: float, penetracion: float}
     */
    public function handle(Collection $seccionIds): array
    {
        $coberturas = CoberturaSeccion::query()->whereIn('seccion_id', $seccionIds)->get();

        $capturados = (int) $coberturas->sum('capturados');
        $meta = (int) $coberturas->sum('meta');
        $verificados = (int) $coberturas->sum('verificados');

        // Denominador de penetración/movilización: lista_nominal de secciones
        // (conteo INE), NO estadisticas_seccion.lista_nominal_2024 —columnas
        // distintas, misma semántica, fuentes distintas—.
        $listaNominal = (int) Seccion::query()->whereIn('id', $seccionIds)->sum('lista_nominal');

        return [
            'capturados' => $capturados,
            'meta' => $meta,
            'verificados' => $verificados,
            'cobertura' => $meta > 0 ? round($capturados / $meta, 4) : 0.0,
            'movilizacion_verificada' => $listaNominal > 0 ? round($verificados / $listaNominal, 4) : 0.0,
            'penetracion' => $listaNominal > 0 ? round($capturados / $listaNominal, 4) : 0.0,
        ];
    }
}
