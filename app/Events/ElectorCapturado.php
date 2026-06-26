<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Se dispara al persistir un elector. Detona la actualización incremental de
 * cobertura_seccion (ADR-003). Lleva tenant_id explícito porque el listener
 * corre fuera del contexto de request.
 */
class ElectorCapturado
{
    use Dispatchable;

    public function __construct(
        public int $tenantId,
        public int $seccionId,
    ) {}
}
