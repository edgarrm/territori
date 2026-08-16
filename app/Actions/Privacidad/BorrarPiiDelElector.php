<?php

namespace App\Actions\Privacidad;

use App\Models\Elector;

/**
 * Punto único de definición de **qué es dato personal en un elector** y cómo se
 * borra. Lo usan la Cancelación ARCO ([CancelarElector]) y la retención por
 * cierre de campaña ([PurgarDatosCommand]).
 *
 * Existe precisamente por el fallo P1/P2: el scrub estaba escrito a mano en un
 * solo sitio, así que cuando se agregó la columna `email` nadie la sumó, y las
 * notas del timeline —texto libre sobre la persona— nunca entraron. Con la
 * lista en un solo lugar, agregar una columna con PII obliga a tocar este
 * archivo.
 *
 * No borra la fila: la baja (lógica o física) la decide quien llama.
 */
class BorrarPiiDelElector
{
    public function handle(Elector $elector): void
    {
        // nombre/telefono son NOT NULL → cadena vacía; el resto a null.
        $elector->forceFill([
            'nombre' => '',
            'telefono' => '',
            'telefono_hash' => null,
            'email' => null,
            'domicilio' => null,
            'ubicacion' => null,
            'observaciones' => null,
        ])->save();

        // Las notas del timeline son dato personal igual que el domicilio. La
        // fila se conserva (el conteo de contactos alimenta métricas) pero sin
        // su contenido; el cascadeOnDelete de la FK no sirve aquí porque la
        // baja del elector es lógica.
        $elector->interacciones()->update(['nota' => null]);
    }
}
