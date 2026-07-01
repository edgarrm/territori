<?php

namespace App\Actions\Electores;

use App\Exceptions\ElectorDuplicado;
use App\Models\Elector;
use App\Support\Telefono;

/**
 * Edita los datos de contacto / nota fija de un elector. Si cambia el teléfono,
 * re-normaliza y recalcula telefono_hash; si el nuevo hash choca con OTRO elector
 * del tenant, lanza ElectorDuplicado (la capa HTTP responde 409). No toca
 * seccion_id / membership_id / consentimiento.
 */
class ActualizarElector
{
    /**
     * @param  array<string, mixed>  $datos
     *
     * @throws ElectorDuplicado si el nuevo teléfono ya existe en otro elector del tenant
     */
    public function handle(Elector $elector, array $datos): Elector
    {
        $cambios = [];

        if (array_key_exists('nombre', $datos)) {
            $cambios['nombre'] = $datos['nombre'];
        }

        if (array_key_exists('email', $datos)) {
            $cambios['email'] = $datos['email'];
        }

        if (array_key_exists('domicilio', $datos)) {
            $cambios['domicilio'] = $datos['domicilio'];
        }

        if (array_key_exists('observaciones', $datos)) {
            $cambios['observaciones'] = $datos['observaciones'];
        }

        if (array_key_exists('telefono', $datos)) {
            $nuevoHash = Telefono::hash((string) $datos['telefono']);

            if ($nuevoHash !== $elector->telefono_hash) {
                $existente = Elector::query()
                    ->where('telefono_hash', $nuevoHash)
                    ->where('id', '!=', $elector->id)
                    ->first();

                if ($existente !== null) {
                    throw new ElectorDuplicado($existente);
                }

                $cambios['telefono'] = $datos['telefono'];
                $cambios['telefono_hash'] = $nuevoHash;
            }
        }

        if ($cambios !== []) {
            $elector->update($cambios);
        }

        return $elector;
    }
}
