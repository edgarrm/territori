<?php

namespace App\Support\Encuestas;

use App\Contracts\Encuestas\FuenteEncuesta;
use App\Enums\CanalEncuesta;
use App\Models\Encuesta;
use App\Services\Encuestas\GoogleSheetsFuente;

/**
 * Resuelve la implementación concreta de FuenteEncuesta por Encuesta.canal.
 * 'nativo' no es un canal pull (recibe respuestas por POST) y no tiene
 * FuenteEncuesta: devuelve null.
 */
class ResolverFuenteEncuesta
{
    public function resolver(Encuesta $encuesta): ?FuenteEncuesta
    {
        return match ($encuesta->canal) {
            CanalEncuesta::GoogleSheets => app(GoogleSheetsFuente::class),
            CanalEncuesta::Nativo => null,
        };
    }
}
