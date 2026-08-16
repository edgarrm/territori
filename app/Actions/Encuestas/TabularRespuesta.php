<?php

namespace App\Actions\Encuestas;

use App\Models\Encuesta;
use App\Models\EncuestaRespuestaAgregada;

/**
 * Única vía de escritura de encuesta_respuestas_agregadas. Nunca persiste la
 * respuesta cruda individual: solo incrementa el conteo agregado por
 * (pregunta, ámbito, opción). Ignora silenciosamente cualquier pregunta que
 * no esté incluida o que no sea de opción única (texto_libre/numerica no se
 * tabulan, aunque vengan en el payload).
 */
class TabularRespuesta
{
    /**
     * @param  array<int|string, string>  $respuestas  pregunta_id => opción
     */
    public function handle(Encuesta $encuesta, string $ambitoTipo, int $ambitoId, array $respuestas): void
    {
        $preguntas = $encuesta->preguntas()
            ->where('incluida', true)
            ->where('tipo', 'opcion_unica')
            ->get()
            ->keyBy('id');

        foreach ($respuestas as $preguntaId => $opcion) {
            $pregunta = $preguntas->get((int) $preguntaId);

            if ($pregunta === null) {
                continue;
            }

            $fila = EncuestaRespuestaAgregada::query()
                ->where('encuesta_id', $encuesta->id)
                ->where('pregunta_id', $pregunta->id)
                ->where('ambito_tipo', $ambitoTipo)
                ->where('ambito_id', $ambitoId)
                ->where('opcion', $opcion)
                ->first();

            if ($fila !== null) {
                $fila->update([
                    'conteo' => $fila->conteo + 1,
                    'actualizado_en' => now(),
                ]);

                continue;
            }

            EncuestaRespuestaAgregada::create([
                'encuesta_id' => $encuesta->id,
                'pregunta_id' => $pregunta->id,
                'ambito_tipo' => $ambitoTipo,
                'ambito_id' => $ambitoId,
                'opcion' => $opcion,
                'conteo' => 1,
                'actualizado_en' => now(),
            ]);
        }
    }
}
