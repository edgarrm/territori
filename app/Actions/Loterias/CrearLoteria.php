<?php

namespace App\Actions\Loterias;

use App\Models\Loteria;
use App\Models\Membership;
use App\Models\Seccion;
use Illuminate\Validation\ValidationException;

/**
 * Crea una lotería a cargo de un encargado (membership) en una sección. La
 * sección debe ser una zona que el encargado tiene asignada.
 */
class CrearLoteria
{
    /**
     * @param  array<string, mixed>  $datos
     *
     * @throws ValidationException si la sección no es una zona asignada del encargado
     */
    public function handle(Membership $encargado, array $datos): Loteria
    {
        $seccion = Seccion::findOrFail((int) $datos['seccion_id']);

        if (! $encargado->puedeCapturarEnSeccion($seccion->id)) {
            throw ValidationException::withMessages([
                'seccion_id' => 'Solo puedes crear lotería en las secciones que tienes asignadas.',
            ]);
        }

        return Loteria::create([
            'membership_id' => $encargado->id,
            'seccion_id' => $seccion->id,
            'nombre' => $datos['nombre'],
            'fecha' => $datos['fecha'],
        ]);
    }
}
