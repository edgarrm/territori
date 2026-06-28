<?php

namespace App\Actions\Privacidad;

use App\Models\SolicitudArco;

/**
 * Registra una solicitud ARCO (LFPDPPP, ADR-004) en estado pendiente, para que
 * un coordinador la atienda. No borra ni modifica al elector: la Cancelación
 * ejecutada vive en CancelarElector.
 */
class RegistrarSolicitudArco
{
    /**
     * @param  array<string, mixed>  $datos
     */
    public function handle(array $datos): SolicitudArco
    {
        return SolicitudArco::create([
            'elector_id' => empty($datos['elector_id']) ? null : (int) $datos['elector_id'],
            'tipo' => $datos['tipo'],
            'estado' => 'pendiente',
            'solicitado_en' => now(),
        ]);
    }
}
