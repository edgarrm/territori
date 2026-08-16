<?php

namespace App\Http\Controllers;

use App\Actions\Encuestas\TabularRespuesta;
use App\Http\Requests\StoreEncuestaRespuestaRequest;
use App\Models\Encuesta;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

class EncuestaRespuestaController extends Controller
{
    /**
     * Flujo brigadista (autenticado): incrementa el agregado, nunca persiste
     * la respuesta cruda individual.
     *
     * Resolución manual (anti-binding): SubstituteBindings corre antes que
     * ResolveTenant en el stack web.
     */
    public function store(StoreEncuestaRespuestaRequest $request, string $encuesta, TabularRespuesta $tabular): JsonResponse
    {
        $modelo = Encuesta::query()->findOrFail($encuesta);

        $validado = $request->validated();

        $this->validarOpciones($modelo, $validado['respuestas']);

        $tabular->handle($modelo, $modelo->ambito_tipo, (int) $validado['ambito_id'], $validado['respuestas']);

        return response()->json(['ok' => true], 201);
    }

    /**
     * Cada opción debe pertenecer al catálogo de su pregunta (solo aplica a
     * opcion_unica; texto_libre/numerica no tienen catálogo que validar).
     *
     * @param  array<int|string, string>  $respuestas
     */
    private function validarOpciones(Encuesta $modelo, array $respuestas): void
    {
        $preguntas = $modelo->preguntas()->get()->keyBy('id');

        foreach ($respuestas as $preguntaId => $opcion) {
            $pregunta = $preguntas->get((int) $preguntaId);

            if ($pregunta === null) {
                throw ValidationException::withMessages([
                    'respuestas' => 'Una de las preguntas no pertenece a esta encuesta.',
                ]);
            }

            if ($pregunta->tipo !== 'opcion_unica') {
                continue;
            }

            $opcionesValidas = $pregunta->opciones ?? [];

            if (! in_array($opcion, $opcionesValidas, true)) {
                throw ValidationException::withMessages([
                    "respuestas.{$preguntaId}" => 'La opción no pertenece al catálogo de esta pregunta.',
                ]);
            }
        }
    }
}
