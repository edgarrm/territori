<?php

namespace App\Actions\Campana;

use App\Models\Membership;
use App\Models\Tenant;
use App\Models\User;
use RuntimeException;

class InvitarMiembro
{
    /**
     * Crea (o reusa) un user por email y le da membership en el tenant con
     * el rol indicado. Si el rol es brigadista y se activa de entrada,
     * valida el límite de brigadistas del tenant.
     *
     * @param  array{email: string, name?: string, rol: string, meta_diaria?: int|null, activo?: bool}  $datos
     */
    public function handle(Tenant $tenant, array $datos): Membership
    {
        $activo = $datos['activo'] ?? true;

        if ($activo && $datos['rol'] === 'brigadista' && ! $tenant->puedeActivarBrigadista()) {
            throw new RuntimeException('Se alcanzó el límite de brigadistas activos para esta campaña. Actualiza tu plan para invitar a más.');
        }

        $user = User::firstOrCreate(
            ['email' => $datos['email']],
            ['name' => $datos['name'] ?? $datos['email'], 'password' => bcrypt(str()->random(32))],
        );

        return Membership::create([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'rol' => $datos['rol'],
            'meta_diaria' => $datos['meta_diaria'] ?? null,
            'activo' => $activo,
            'activado_en' => $activo ? now() : null,
        ]);
    }
}
