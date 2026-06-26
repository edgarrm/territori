<?php

namespace App\Console\Commands;

use App\Models\CoberturaSeccion;
use App\Models\MetaSeccion;
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
    public function handle(): int
    {
        $tenant = Tenant::find((int) $this->argument('tenant'));

        if (! $tenant) {
            $this->error("Tenant {$this->argument('tenant')} no existe.");

            return self::FAILURE;
        }

        TenantContext::set($tenant);

        $secciones = $tenant->municipio->secciones;
        $metasPorSeccion = MetaSeccion::pluck('meta_capturas', 'seccion_id');

        foreach ($secciones as $seccion) {
            $capturados = 0;
            $meta = $metasPorSeccion[$seccion->id] ?? 0;
            $listaNominal = $seccion->lista_nominal;

            CoberturaSeccion::upsertParaSeccion($seccion->id, [
                'capturados' => $capturados,
                'meta' => $meta,
                'cobertura' => $meta > 0 ? round($capturados / $meta, 4) : 0,
                'penetracion' => $listaNominal > 0 ? round($capturados / $listaNominal, 4) : 0,
                'actualizado_en' => now(),
            ]);
        }

        $this->info("Cobertura recalculada para {$secciones->count()} secciones del tenant {$tenant->id}.");

        return self::SUCCESS;
    }
}
