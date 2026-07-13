<?php

namespace App\Actions\Cobertura;

use App\Models\CoberturaSeccion;
use App\Models\Elector;
use App\Models\MetaSeccion;
use App\Models\Seccion;

/**
 * Recalcula la fila de cobertura_seccion de UNA sección a partir del conteo real
 * de electores del tenant activo. Idempotente: recontar deja el mismo resultado.
 * Compartido por el job incremental (ElectorCapturado) y el comando de recálculo
 * completo. Requiere TenantContext activo (las queries y el upsert dependen de él).
 */
class RecalcularCoberturaSeccion
{
    public function handle(Seccion $seccion): void
    {
        $capturados = Elector::query()->where('seccion_id', $seccion->id)->count();
        $verificados = Elector::query()->where('seccion_id', $seccion->id)->whereNotNull('verificado_en')->count();
        $meta = (int) (MetaSeccion::query()->where('seccion_id', $seccion->id)->value('meta_capturas') ?? 0);
        $listaNominal = (int) ($seccion->lista_nominal ?? 0);

        CoberturaSeccion::upsertParaSeccion($seccion->id, [
            'capturados' => $capturados,
            'meta' => $meta,
            'cobertura' => $meta > 0 ? round($capturados / $meta, 4) : 0,
            'penetracion' => $listaNominal > 0 ? round($capturados / $listaNominal, 4) : 0,
            'verificados' => $verificados,
            'movilizacion_verificada' => $listaNominal > 0 ? round($verificados / $listaNominal, 4) : 0,
            'actualizado_en' => now(),
        ]);
    }
}
