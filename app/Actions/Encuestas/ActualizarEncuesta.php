<?php

namespace App\Actions\Encuestas;

use App\Models\Encuesta;
use App\Models\EncuestaPregunta;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Actualiza nombre/ámbito/modo de acceso de una encuesta y reemplaza su set
 * de preguntas (borra + recrea; sin re-tabulación retroactiva, fuera de
 * alcance de F8 — ver spec). Genera `token_publico` si el modo de acceso pasa
 * a incluir 'publico' y todavía no tenía uno.
 */
class ActualizarEncuesta
{
    /**
     * @param  array<string, mixed>  $datos
     */
    public function handle(Encuesta $encuesta, array $datos): Encuesta
    {
        return DB::transaction(function () use ($encuesta, $datos): Encuesta {
            $modoAcceso = $datos['modo_acceso'] ?? $encuesta->modo_acceso;
            $esPublica = in_array($modoAcceso, ['publico', 'ambos'], true);

            $encuesta->update([
                'nombre' => $datos['nombre'] ?? $encuesta->nombre,
                'ambito_tipo' => $datos['ambito_tipo'] ?? $encuesta->ambito_tipo,
                'modo_acceso' => $modoAcceso,
                'token_publico' => $esPublica ? ($encuesta->token_publico ?? Str::random(40)) : null,
                'estado' => $datos['estado'] ?? $encuesta->estado,
            ]);

            if (array_key_exists('preguntas', $datos)) {
                $encuesta->preguntas()->delete();

                foreach ($datos['preguntas'] as $orden => $pregunta) {
                    EncuestaPregunta::create([
                        'encuesta_id' => $encuesta->id,
                        'campo' => null,
                        'etiqueta' => $pregunta['etiqueta'],
                        'tipo' => $pregunta['tipo'],
                        'opciones' => $pregunta['opciones'] ?? null,
                        'incluida' => true,
                        'orden' => $pregunta['orden'] ?? $orden,
                    ]);
                }
            }

            return $encuesta->fresh('preguntas');
        });
    }
}
