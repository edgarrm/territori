<?php

namespace App\Listeners;

use App\Events\ElectorCapturado;
use App\Jobs\ActualizarCoberturaSeccion;

class ActualizarCoberturaAlCapturar
{
    public function handle(ElectorCapturado $event): void
    {
        ActualizarCoberturaSeccion::dispatch($event->tenantId, $event->seccionId);
    }
}
