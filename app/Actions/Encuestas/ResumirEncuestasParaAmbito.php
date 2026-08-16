<?php

namespace App\Actions\Encuestas;

use App\Models\Encuesta;

/**
 * Encuestas activas con al menos una respuesta tabulada en un ámbito
 * (sección/zona) concreto (F10): alimenta la card "Encuesta: {nombre}" de la
 * ficha de sección (MapaController::resumenSeccion) y de zona
 * (ZonaController::resumen). Reusa ResumirRespuestasEncuesta con un solo
 * ambito_id — cero lógica de agregación nueva.
 */
class ResumirEncuestasParaAmbito
{
    /**
     * @return list<array{encuesta_id: int, nombre: string, preguntas: list<array{pregunta_id: int, etiqueta: string, total_respuestas: int, opciones: list<array{opcion: string, conteo: int, pct: float}>}>}>
     */
    public function handle(string $ambitoTipo, int $ambitoId): array
    {
        $encuestas = Encuesta::query()
            ->where('estado', 'activa')
            ->where('ambito_tipo', $ambitoTipo)
            ->with('preguntas')
            ->orderBy('nombre')
            ->get();

        $resumir = new ResumirRespuestasEncuesta;

        return array_values($encuestas
            ->map(function (Encuesta $encuesta) use ($ambitoTipo, $ambitoId, $resumir): ?array {
                $resultado = $resumir->handle($encuesta, $ambitoTipo, collect([$ambitoId]))[0] ?? null;
                $preguntas = $resultado['preguntas'] ?? [];

                $tieneRespuestas = collect($preguntas)->contains(fn (array $p): bool => $p['total_respuestas'] > 0);

                if (! $tieneRespuestas) {
                    return null;
                }

                return [
                    'encuesta_id' => $encuesta->id,
                    'nombre' => $encuesta->nombre,
                    'preguntas' => $preguntas,
                ];
            })
            ->filter()
            ->all());
    }
}
