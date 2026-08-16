<?php

namespace App\Enums;

/**
 * Catálogo de canales de encuesta soportados (F8). Vive en código, no en un
 * CHECK de BD, para no migrar cada vez que se agregue un canal nuevo
 * (Twilio/voz queda para una spec futura).
 */
enum CanalEncuesta: string
{
    case Nativo = 'nativo';
    case GoogleSheets = 'google_sheets';

    public function label(): string
    {
        return match ($this) {
            self::Nativo => 'Formulario propio',
            self::GoogleSheets => 'Google Sheets',
        };
    }
}
