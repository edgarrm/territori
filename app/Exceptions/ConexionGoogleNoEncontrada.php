<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * El tenant no tiene conexiones_google todavía. Se lanza desde ClienteSheets
 * y se traduce a un error claro (422) en el controlador, dirigiendo a
 * /settings/google — nunca una excepción no controlada (F9, criterio 7).
 */
class ConexionGoogleNoEncontrada extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Este tenant no tiene una cuenta de Google conectada. Conéctala en Configuración → Google.');
    }
}
