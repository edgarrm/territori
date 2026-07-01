<?php

namespace App\Actions\Loterias;

use App\Models\Loteria;
use App\Models\Membership;
use App\Models\Seccion;
use Illuminate\Validation\ValidationException;

class AbrirLoteria
{
    /**
     * Abre una lotería para el brigadista en la sección dada. Si ya tiene una
     * abierta, devuelve la existente (no se permiten dos simultáneas). La
     * sección debe ser una zona asignada del brigadista.
     */
    public function handle(Membership $membership, Seccion $seccion): Loteria
    {
        if (! $membership->puedeCapturarEnSeccion($seccion->id)) {
            throw ValidationException::withMessages([
                'seccion_id' => 'Solo puedes abrir lotería en las secciones que tienes asignadas.',
            ]);
        }

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
