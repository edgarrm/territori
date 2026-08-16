<?php

namespace App\Actions\Electores;

use App\Actions\Privacidad\RegistrarAcceso;
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
    public function __construct(private RegistrarAcceso $bitacora) {}

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
                // Solo el id: cargar el modelo completo metería PII descifrada en
                // la excepción, y de ahí al log (P13).
                $existenteId = Elector::query()
                    ->where('telefono_hash', $nuevoHash)
                    ->where('id', '!=', $elector->id)
                    ->value('id');

                if ($existenteId !== null) {
                    throw new ElectorDuplicado((int) $existenteId);
                }

                $cambios['telefono'] = $datos['telefono'];
                $cambios['telefono_hash'] = $nuevoHash;
            }
        }

        if ($cambios !== []) {
            $elector->update($cambios);

            // Solo los nombres de los campos tocados, nunca sus valores.
            // telefono_hash es derivado de telefono: no aporta a la traza.
            $this->bitacora->handle('elector.actualizado', 'elector', $elector->id, [
                'campos' => array_values(array_diff(array_keys($cambios), ['telefono_hash'])),
            ]);
        }

        return $elector;
    }
}
