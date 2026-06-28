<?php

namespace App\Actions\Campana;

use App\Models\Membership;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class RegistrarCampana
{
    public function __construct(private CrearCampana $crearCampana) {}

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

            return $tenant;
        });
    }
}
