<?php

namespace App\Support;

/**
 * Enmascaramiento de datos personales (PII) para presentación. Un brigadista
 * solo ve la PII completa de los electores que capturó; del resto recibe la
 * versión enmascarada (LFPDPPP / mínimo privilegio, ADR-004).
 */
class Pii
{
    /**
     * Deja visibles solo los últimos 4 dígitos: 5511112222 → ••••••2222.
     */
    public static function enmascararTelefono(?string $telefono): ?string
    {
        if ($telefono === null || $telefono === '') {
            return $telefono;
        }

        $digitos = preg_replace('/\D+/', '', $telefono) ?? '';

        if (strlen($digitos) <= 4) {
            return str_repeat('•', strlen($digitos));
        }

        return str_repeat('•', strlen($digitos) - 4).substr($digitos, -4);
    }

    /**
     * Oculta el domicilio por completo (no es enmascarable de forma útil).
     */
    public static function enmascararDomicilio(?string $domicilio): ?string
    {
        return ($domicilio === null || $domicilio === '') ? $domicilio : '•••';
    }

    /**
     * Deja visible solo la inicial y el dominio: juan@correo.com → j•••@correo.com.
     * Sin arroba (dato inválido) se oculta por completo.
     */
    public static function enmascararEmail(?string $email): ?string
    {
        if ($email === null || $email === '') {
            return $email;
        }

        $arroba = strpos($email, '@');

        if ($arroba === false) {
            return '•••';
        }

        $local = substr($email, 0, $arroba);
        $dominio = substr($email, $arroba);
        $inicial = $local === '' ? '' : substr($local, 0, 1);

        return $inicial.'•••'.$dominio;
    }
}
