<?php

namespace App\Exceptions;

use App\Models\Elector;
use RuntimeException;

/**
 * Se lanza cuando ya existe un elector con el mismo telefono_hash en el tenant.
 * Lleva el elector existente para que la capa HTTP responda 409 con su id.
 */
class ElectorDuplicado extends RuntimeException
{
    public function __construct(public Elector $existente)
    {
        parent::__construct('Ya existe un elector con ese teléfono en esta campaña.');
    }
}
