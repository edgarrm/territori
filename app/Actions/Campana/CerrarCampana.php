<?php

namespace App\Actions\Campana;

use App\Actions\Privacidad\RegistrarAcceso;
use App\Models\Tenant;

/**
 * Cierre de campaña: el disparador de la retención por finalidad agotada
 * (LFPDPPP art. 11). Al cerrarla arranca el plazo tras el cual
 * `territori:purgar-datos` borra la PII de sus electores.
 *
 * Existe como acción de la app y no solo como un UPDATE manual en la base
 * porque un control legal que depende de que alguien recuerde correr SQL no se
 * ejecuta. Deja traza: el cierre es el momento en que la campaña deja de tener
 * base legítima para tratar los datos, y hay que poder acreditar cuándo fue y
 * quién lo hizo.
 */
class CerrarCampana
{
    public function __construct(private RegistrarAcceso $bitacora) {}

    public function handle(Tenant $tenant): Tenant
    {
        if ($tenant->estado === 'cerrada') {
            return $tenant;
        }

        $tenant->cerrar();

        $this->bitacora->handle('campana.cerrada', 'tenant', $tenant->id, [
            'retencion_dias' => (int) config('app.retencion.campana_cerrada_dias'),
        ]);

        return $tenant;
    }
}
