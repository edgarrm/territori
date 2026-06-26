<?php

namespace App\Actions\Metas;

use App\Models\CoberturaSeccion;
use App\Models\MetaSeccion;
use App\Models\Seccion;
use App\Support\Tenancy\TenantContext;

class DefinirMetaSeccion
{
    /**
     * Fija la meta de una sección para el tenant activo y refresca cobertura_seccion.
     */
    public function handle(Seccion $seccion, string $fuenteMeta, ?int $metaCapturas = null, ?float $pct = null): MetaSeccion
    {
        $metaCapturas = $fuenteMeta === 'lista_nominal_pct'
            ? (int) round(($seccion->lista_nominal ?? 0) * ($pct ?? 0) / 100)
            : ($metaCapturas ?? 0);

        $meta = MetaSeccion::updateOrCreate(
            ['seccion_id' => $seccion->id],
            [
                'meta_capturas' => $metaCapturas,
                'fuente_meta' => $fuenteMeta,
                'pct_lista_nominal' => $fuenteMeta === 'lista_nominal_pct' ? $pct : null,
            ],
        );

        $this->refrescarCobertura($seccion, $metaCapturas);

        return $meta;
    }

    private function refrescarCobertura(Seccion $seccion, int $metaCapturas): void
    {
        $tenantId = TenantContext::get()?->id;

        $cobertura = CoberturaSeccion::where('tenant_id', $tenantId)
            ->where('seccion_id', $seccion->id)
            ->first();

        $capturados = $cobertura->capturados ?? 0;

        CoberturaSeccion::upsertParaSeccion($seccion->id, [
            'capturados' => $capturados,
            'meta' => $metaCapturas,
            'cobertura' => $metaCapturas > 0 ? round($capturados / $metaCapturas, 4) : 0,
            'penetracion' => $cobertura->penetracion ?? 0,
            'actualizado_en' => now(),
        ]);
    }
}
