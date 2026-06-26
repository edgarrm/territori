<?php

namespace App\Jobs;

use App\Actions\Cobertura\RecalcularCoberturaSeccion;
use App\Models\Seccion;
use App\Models\Tenant;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Actualiza cobertura_seccion de una sección tras una captura. Los jobs encolados
 * NO heredan el currentTenant del request: se fija explícitamente desde tenant_id.
 */
class ActualizarCoberturaSeccion implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public int $tenantId,
        public int $seccionId,
    ) {}

    public function handle(RecalcularCoberturaSeccion $recalcular): void
    {
        TenantContext::set(Tenant::findOrFail($this->tenantId));

        $seccion = Seccion::findOrFail($this->seccionId);

        $recalcular->handle($seccion);
    }
}
