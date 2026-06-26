<?php

namespace App\Support;

use Illuminate\Support\Facades\Config;

/**
 * Normalización y hash determinista de teléfonos para dedup de electores.
 * El hash es HMAC-SHA256 sobre el número normalizado con APP_KEY (ADR-004):
 * no es reversible y no es tabla-arcoíris-able como un SHA plano.
 */
class Telefono
{
    /**
     * Deja solo dígitos y devuelve los últimos 10 (formato MX). Null si <10.
     */
    public static function normalizar(string $telefono): ?string
    {
        $digitos = preg_replace('/\D+/', '', $telefono) ?? '';

        if (strlen($digitos) < 10) {
            return null;
        }

        return substr($digitos, -10);
    }

    /**
     * Hash determinista para dedup, o null si el número es inválido.
     */
    public static function hash(string $telefono): ?string
    {
        $normalizado = self::normalizar($telefono);

        if ($normalizado === null) {
            return null;
        }

        return hash_hmac('sha256', $normalizado, (string) Config::get('app.key'));
    }
}
