<?php

namespace App\Actions\RedesCiudadanas;

use App\Models\RedCiudadana;

/**
 * Crea una red ciudadana en el tenant activo y le asigna el enlace responsable.
 * El tenant_id lo fija el trait BelongsToTenant; el enlace ya fue validado como
 * membership del tenant por el FormRequest.
 */
class CrearRedCiudadana
{
    /**
     * @param  array<string, mixed>  $datos
     */
    public function handle(array $datos): RedCiudadana
    {
        return RedCiudadana::create([
            'enlace_membership_id' => (int) $datos['enlace_membership_id'],
            'nombre' => $datos['nombre'],
            'descripcion' => $datos['descripcion'] ?? null,
        ]);
    }
}
