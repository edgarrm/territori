<?php

namespace App\Console\Commands;

use App\Actions\Zonas\CalcularInterseccionZona;
use App\Models\Tenant;
use App\Models\Zona;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('territori:recalcular-zonas {tenant}')]
#[Description('Recalcula zona_seccion para todas las zonas de un tenant (idempotente), red de seguridad tras actualizar cartografía.')]
class RecalcularZonasCommand extends Command
{
    public function handle(CalcularInterseccionZona $calcular): int
    {
        $tenant = Tenant::find((int) $this->argument('tenant'));

        if (! $tenant) {
            $this->error("Tenant {$this->argument('tenant')} no existe.");

            return self::FAILURE;
        }

        TenantContext::set($tenant);

        $zonas = Zona::query()->get();

        foreach ($zonas as $zona) {
            $calcular->handle($zona);
        }

        $this->info("Zonas recalculadas para {$zonas->count()} zonas del tenant {$tenant->id}.");

        return self::SUCCESS;
    }
}
