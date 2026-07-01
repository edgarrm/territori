<?php

namespace Tests\Concerns;

use App\Models\Membership;
use App\Models\Seccion;

/**
 * Helper de tests: asigna zonas (brigadista_seccion) a una membership. Necesario
 * porque un brigadista solo puede capturar en sus secciones asignadas.
 */
trait AsignaZonas
{
    /**
     * Asigna al brigadista todas las secciones de su municipio (lo deja capturar
     * en cualquier parte del municipio, como antes de la restricción por zona).
     */
    protected function asignarTodasLasZonas(Membership $membership, int $municipioId): void
    {
        $ids = Seccion::query()
            ->where('municipio_id', $municipioId)
            ->pluck('id')
            ->mapWithKeys(fn (int $id): array => [$id => ['tenant_id' => $membership->tenant_id]])
            ->all();

        $membership->secciones()->syncWithoutDetaching($ids);
    }
}
