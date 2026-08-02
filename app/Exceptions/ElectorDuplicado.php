<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Se lanza cuando ya existe un elector con el mismo telefono_hash en el tenant.
 * Lleva solo el id del elector existente, nunca el modelo (P13): una excepción
 * acaba en el log, y con LOG_LEVEL=debug el trace serializaría la PII descifrada
 * por los casts. La capa HTTP responde 409 sin revelar ese id.
 */
class ElectorDuplicado extends RuntimeException
{
    public function __construct(public int $electorId)
    {
        parent::__construct('Ya existe un elector con ese teléfono en esta campaña.');
    }
}
