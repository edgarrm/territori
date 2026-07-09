<?php

namespace App\Enums;

/**
 * Tipo de sección por tamaño de lista nominal (F2, análisis electoral por
 * partido). Se calcula on-read con los umbrales del tenant, no se persiste.
 */
enum TipoSeccion: string
{
    case Alfa = 'alfa';
    case Beta = 'beta';
    case Gama = 'gama';

    public function label(): string
    {
        return match ($this) {
            self::Alfa => 'Alfa',
            self::Beta => 'Beta',
            self::Gama => 'Gama',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Alfa => '#7c3aed',
            self::Beta => '#2563eb',
            self::Gama => '#0d9488',
        };
    }

    public static function desdeListaNominal(?int $listaNominal, int $umbralAlfa, int $umbralBeta): ?self
    {
        if ($listaNominal === null || $listaNominal < 1) {
            return null;
        }

        if ($listaNominal >= $umbralAlfa) {
            return self::Alfa;
        }

        if ($listaNominal >= $umbralBeta) {
            return self::Beta;
        }

        return self::Gama;
    }
}
