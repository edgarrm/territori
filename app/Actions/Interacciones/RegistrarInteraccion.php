<?php

namespace App\Actions\Interacciones;

use App\Models\Elector;
use App\Models\Interaccion;
use App\Models\Membership;

/**
 * Registra una interacción en el timeline de un elector. El membership_id sale
 * del usuario autenticado en el tenant (resuelto por el controlador), nunca del
 * request. tipo=nota no lleva resultado; fecha por defecto = ahora.
 */
class RegistrarInteraccion
{
    /**
     * @param  array<string, mixed>  $datos
     */
    public function handle(Elector $elector, array $datos, Membership $membership): Interaccion
    {
        $tipo = $datos['tipo'];

        return Interaccion::create([
            'elector_id' => $elector->id,
            'membership_id' => $membership->id,
            'tipo' => $tipo,
            'resultado' => $tipo === 'nota' ? null : ($datos['resultado'] ?? null),
            'nota' => $datos['nota'] ?? null,
            'fecha' => $datos['fecha'] ?? now(),
            'proximo_seguimiento' => $datos['proximo_seguimiento'] ?? null,
        ]);
    }
}
