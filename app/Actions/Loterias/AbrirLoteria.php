<?php

namespace App\Actions\Loterias;

use App\Models\Loteria;
use App\Models\Membership;
use App\Models\Seccion;

class AbrirLoteria
{
    /**
     * Abre una lotería para el brigadista en la sección dada. Si ya tiene una
     * abierta, devuelve la existente (no se permiten dos simultáneas).
     */
    public function handle(Membership $membership, Seccion $seccion): Loteria
    {
        $abierta = Loteria::query()
            ->where('membership_id', $membership->id)
            ->whereNull('cerrada_en')
            ->latest('abierta_en')
            ->first();

        if ($abierta !== null) {
            return $abierta;
        }

        return Loteria::create([
            'membership_id' => $membership->id,
            'seccion_id' => $seccion->id,
            'abierta_en' => now(),
        ]);
    }
}
