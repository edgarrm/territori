<?php

namespace App\Enums;

/**
 * Estatus de una sección desde la perspectiva del partido de la campaña
 * (F2, análisis electoral por partido): resultado de comparar el bloque
 * propio contra el mejor rival en `estadisticas_seccion.votos_partidos`.
 */
enum CompetitividadSeccion: string
{
    case GanadaFranca = 'ganada_franca';
    case Competida = 'competida';
    case Empatada = 'empatada';
    case Perdida = 'perdida';
    case SinDatos = 'sin_datos';

    public function label(): string
    {
        return match ($this) {
            self::GanadaFranca => 'Ganada franca',
            self::Competida => 'Competida',
            self::Empatada => 'Empatada',
            self::Perdida => 'Perdida',
            self::SinDatos => 'Sin datos',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::GanadaFranca => '#15803d',
            self::Competida => '#f59e0b',
            self::Empatada => '#6b7280',
            self::Perdida => '#dc2626',
            self::SinDatos => '#9ca3af',
        };
    }
}
