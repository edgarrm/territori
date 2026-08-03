<?php

namespace App\Support;

use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Config;
use RuntimeException;

/**
 * Normalización y hash determinista de teléfonos para dedup de electores.
 * El hash es HMAC-SHA256 sobre el número normalizado con APP_KEY + el id de la
 * campaña (ADR-004): no es reversible y no es tabla-arcoíris-able como un SHA
 * plano.
 *
 * El salt por campaña cierra P11: con solo APP_KEY, el mismo teléfono producía
 * **el mismo hash** en dos campañas rivales del mismo host, así que comparar
 * las columnas `telefono_hash` de dos tenants revelaba qué contactos comparten
 * — un cruce de padrones sin descifrar un solo dato.
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
     * Hash determinista para dedup dentro de una campaña, o null si el número
     * es inválido. El tenant sale del `TenantContext` salvo que se pase
     * explícito (comandos de mantenimiento que recorren varias campañas).
     *
     * @throws RuntimeException si no hay campaña que aporte el salt
     */
    public static function hash(string $telefono, ?int $tenantId = null): ?string
    {
        $normalizado = self::normalizar($telefono);

        if ($normalizado === null) {
            return null;
        }

        $tenantId ??= TenantContext::get()?->id;

        if ($tenantId === null) {
            // Fail-closed, igual que TenantScope: un hash sin salt de campaña
            // volvería a ser comparable entre tenants (P11), y guardarlo así
            // rompería el dedup en silencio.
            throw new RuntimeException(
                'Telefono::hash requiere una campaña activa (o un tenantId explícito): el salt por campaña es parte del hash.'
            );
        }

        return hash_hmac('sha256', $normalizado, Config::get('app.key').'|'.$tenantId);
    }
}
