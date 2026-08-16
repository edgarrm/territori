<?php

namespace App\Actions\Campana;

use App\Actions\Privacidad\RegistrarAcceso;
use App\Models\Membership;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

class RegistrarCampana
{
    public function __construct(
        private CrearCampana $crearCampana,
        private RegistrarAcceso $bitacora,
    ) {}

    /**
     * Crea una campaña (tenant) y deja al usuario creador como admin activo,
     * todo dentro de una transacción para no dejar una campaña huérfana.
     *
     * @param  array{nombre: string, municipio_id: int, plan?: string, limite_brigadistas?: int|null, marca_nombre?: string|null, marca_logo_url?: string|null, marca_color?: string|null, subdominio?: string|null}  $datos
     */
    public function handle(User $creador, array $datos): Tenant
    {
        return DB::transaction(function () use ($creador, $datos): Tenant {
            $tenant = $this->crearCampana->handle($datos);

            Membership::create([
                'tenant_id' => $tenant->id,
                'user_id' => $creador->id,
                'rol' => 'admin',
                'activo' => true,
                'activado_en' => now(),
            ]);

            // La bitácora es tenant-scoped y `RegistrarAcceso` devuelve null sin
            // campaña activa, así que el contexto se fija antes de escribir: al
            // crear la campaña todavía es el tenant anterior (o ninguno).
            // Crear una campaña es el acto que abre un tratamiento de datos
            // personales nuevo; no puede quedar sin traza.
            $anterior = TenantContext::get();
            TenantContext::set($tenant);

            $this->bitacora->handle('campana.creada', 'tenant', $tenant->id, [
                'municipio_id' => $tenant->municipio_id,
                'plan' => $tenant->plan,
            ]);

            $anterior === null ? TenantContext::clear() : TenantContext::set($anterior);

            return $tenant;
        });
    }
}
