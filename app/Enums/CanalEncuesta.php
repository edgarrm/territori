<?php

namespace App\Enums;

/**
 * Catálogo de canales de encuesta soportados (F8). Vive en código, no en un
 * CHECK de BD, para no migrar cada vez que se agregue un canal nuevo
 * (Google Sheets/Twilio llegan en F9+).
 */
enum CanalEncuesta: string
{
    case Nativo = 'nativo';

    public function label(): string
    {
        return match ($this) {
            self::Nativo => 'Formulario propio',
        };
    }
}
