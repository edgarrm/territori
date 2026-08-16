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
     * Oculta un texto libre por completo. Domicilio y observaciones no son
     * enmascarables de forma útil: cualquier fragmento visible puede
     * reidentificar (una calle, el nombre de un familiar, un padecimiento).
     */
    public static function enmascararTexto(?string $texto): ?string
    {
        return ($texto === null || $texto === '') ? $texto : '•••';
    }

    /**
     * Oculta el domicilio por completo (no es enmascarable de forma útil).
     */
    public static function enmascararDomicilio(?string $domicilio): ?string
    {
        return self::enmascararTexto($domicilio);
    }

    /**
     * Reduce el nombre a iniciales dejando visible el primer apellido:
     * "Juan Carlos Pérez García" → "J. C. Pérez G.", "Juan Pérez" → "J. Pérez".
     *
     * El nombre completo junto con la sección y los últimos 4 dígitos del
     * teléfono reidentifica trivialmente a la persona (P6), así que la lista
     * ajena tampoco debe mostrarlo entero. Se conserva el primer apellido
     * porque la lista sigue teniendo que ser navegable para el brigadista.
     *
     * Con tres o más palabras se asume el patrón mexicano (nombre[s] +
     * apellido paterno + apellido materno) y el primer apellido es la
     * penúltima; con dos, la última; con una sola, no queda nada que revelar.
     */
    public static function enmascararNombre(?string $nombre): ?string
    {
        if ($nombre === null || trim($nombre) === '') {
            return $nombre;
        }

        $partes = preg_split('/\s+/', trim($nombre)) ?: [];
        $total = count($partes);

        $visible = match (true) {
            $total >= 3 => $total - 2,
            $total === 2 => 1,
            default => -1,
        };

        return implode(' ', array_map(
            fn (string $parte, int $indice): string => $indice === $visible
                ? $parte
                : mb_strtoupper(mb_substr($parte, 0, 1)).'.',
            $partes,
            array_keys($partes),
        ));
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
