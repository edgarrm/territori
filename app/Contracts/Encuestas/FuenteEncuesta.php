<?php

namespace App\Contracts\Encuestas;

use App\Models\Encuesta;

/**
 * Contrato de un canal "pull" de encuesta (F9+): trae respuestas desde un
 * sistema externo (Google Sheets, y en el futuro Twilio/voz) en vez de
 * recibirlas por POST como el canal nativo.
 */
interface FuenteEncuesta
{
    /**
     * Verifica que la encuesta puede leer su fuente externa ahora mismo
     * (credenciales vigentes, recurso accesible). No sincroniza nada.
     *
     * @return array<string, mixed>
     */
    public function verificarAcceso(Encuesta $encuesta): array;

    /**
     * @return iterable<array{identificador: string, respuestas: array<string, mixed>}>
     */
    public function obtenerRespuestas(Encuesta $encuesta): iterable;
}
