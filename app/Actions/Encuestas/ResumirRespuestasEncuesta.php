<?php

namespace App\Actions\Encuestas;

use App\Models\Encuesta;
use App\Models\EncuestaPregunta;
use App\Models\EncuestaRespuestaAgregada;
use Illuminate\Support\Collection;

/**
 * Desglose de una encuesta por pregunta+opción, para cada ámbito
 * (sección/zona) pedido (F10). Un ámbito sin ninguna respuesta tabulada
 * aparece igual, con todas sus opciones en conteo 0 — para diferenciar "no
 * respondida" de "no existe" (criterio 2 de la spec).
 */
class ResumirRespuestasEncuesta
{
    /**
     * @param  Collection<int, int>  $ambitoIds
     * @return list<array{ambito_id: int, preguntas: list<array{pregunta_id: int, etiqueta: string, total_respuestas: int, opciones: list<array{opcion: string, conteo: int, pct: float}>}>}>
     */
    public function handle(Encuesta $encuesta, string $ambitoTipo, Collection $ambitoIds): array
    {
        $preguntas = $encuesta->preguntas->filter(fn (EncuestaPregunta $p): bool => $p->esTabulable())->values();

        $agregadosPorAmbito = EncuestaRespuestaAgregada::query()
            ->where('encuesta_id', $encuesta->id)
            ->where('ambito_tipo', $ambitoTipo)
            ->whereIn('ambito_id', $ambitoIds)
            ->get()
            ->groupBy('ambito_id');

        return array_values($ambitoIds
            ->map(fn (int $ambitoId): array => [
                'ambito_id' => $ambitoId,
                'preguntas' => $this->preguntasResumen($preguntas, $agregadosPorAmbito->get($ambitoId, collect())),
            ])
            ->all());
    }

    /**
     * @param  Collection<int, EncuestaPregunta>  $preguntas
     * @param  Collection<int, EncuestaRespuestaAgregada>  $filasAmbito
     * @return list<array{pregunta_id: int, etiqueta: string, total_respuestas: int, opciones: list<array{opcion: string, conteo: int, pct: float}>}>
     */
    private function preguntasResumen(Collection $preguntas, Collection $filasAmbito): array
    {
        return array_values($preguntas
            ->map(function (EncuestaPregunta $pregunta) use ($filasAmbito): array {
                $filasPregunta = $filasAmbito->where('pregunta_id', $pregunta->id)->keyBy('opcion');
                $total = (int) $filasPregunta->sum('conteo');

                return [
                    'pregunta_id' => $pregunta->id,
                    'etiqueta' => $pregunta->etiqueta,
                    'total_respuestas' => $total,
                    'opciones' => $this->opcionesResumen($pregunta->opciones ?? [], $filasPregunta, $total),
                ];
            })
            ->all());
    }

    /**
     * @param  array<int, string>  $catalogo
     * @param  Collection<string, EncuestaRespuestaAgregada>  $filasPregunta
     * @return list<array{opcion: string, conteo: int, pct: float}>
     */
    private function opcionesResumen(array $catalogo, Collection $filasPregunta, int $total): array
    {
        return array_values(collect($catalogo)
            ->map(fn (string $opcion): array => [
                'opcion' => $opcion,
                'conteo' => $this->conteoDe($filasPregunta, $opcion),
                'pct' => $total > 0 ? $this->conteoDe($filasPregunta, $opcion) / $total : 0.0,
            ])
            ->sortByDesc('conteo')
            ->all());
    }

    /**
     * @param  Collection<string, EncuestaRespuestaAgregada>  $filasPregunta
     */
    private function conteoDe(Collection $filasPregunta, string $opcion): int
    {
        $fila = $filasPregunta->get($opcion);

        return $fila !== null ? $fila->conteo : 0;
    }
}
