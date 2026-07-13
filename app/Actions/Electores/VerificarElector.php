<?php

namespace App\Actions\Electores;

use App\Jobs\ActualizarCoberturaSeccion;
use App\Models\Elector;
use App\Models\Membership;

/**
 * Marca o quita la verificación de un elector. Verificar deja constancia del
 * canal por el que se confirmaron los datos (via), quién lo confirmó y cuándo.
 * Cualquier cambio re-dispara el recálculo de cobertura_seccion (que recuenta
 * verificados de forma idempotente).
 */
class VerificarElector
{
    public function marcar(Elector $elector, string $via, Membership $membership): void
    {
        $elector->update([
            'verificado_en' => now(),
            'verificado_via' => $via,
            'verificado_membership_id' => $membership->id,
        ]);

        $this->recalcularCobertura($elector);
    }

    public function quitar(Elector $elector): void
    {
        $elector->update([
            'verificado_en' => null,
            'verificado_via' => null,
            'verificado_membership_id' => null,
        ]);

        $this->recalcularCobertura($elector);
    }

    private function recalcularCobertura(Elector $elector): void
    {
        ActualizarCoberturaSeccion::dispatch($elector->tenant_id, $elector->seccion_id);
    }
}
