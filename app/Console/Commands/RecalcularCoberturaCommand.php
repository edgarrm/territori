<?php

namespace App\Console\Commands;

use App\Actions\Cobertura\RecalcularCoberturaSeccion;
use App\Models\Tenant;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('territori:recalcular-cobertura {tenant}')]
#[Description('Recalcula cobertura_seccion para todas las secciones del municipio de un tenant (idempotente).')]
class RecalcularCoberturaCommand extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(RecalcularCoberturaSeccion $recalcular): int
    {
        $tenant = Tenant::find((int) $this->argument('tenant'));

        if (! $tenant) {
            $this->error("Tenant {$this->argument('tenant')} no existe.");

            return self::FAILURE;
        }

        TenantContext::set($tenant);

        $secciones = $tenant->municipio->secciones;

        foreach ($secciones as $seccion) {
            $recalcular->handle($seccion);
        }

        $this->info("Cobertura recalculada para {$secciones->count()} secciones del tenant {$tenant->id}.");

        return self::SUCCESS;
    }
}
