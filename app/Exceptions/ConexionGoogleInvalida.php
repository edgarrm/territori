<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * El refresh_token fue revocado (fuera del control de la app) o Google
 * rechazó el refresco por otra razón. ClienteSheets borra la conexión
 * inválida antes de lanzar esto, para que la UI vuelva a "no conectado" en
 * vez de fallar en bucle.
 */
class ConexionGoogleInvalida extends RuntimeException
{
    public function __construct(string $mensaje = 'Tu conexión con Google ya no es válida. Reconéctala en Configuración → Google.')
    {
        parent::__construct($mensaje);
    }
}
