<?php

namespace App\Actions\Loterias;

use App\Models\Loteria;
use App\Models\Membership;
use App\Models\Seccion;
use App\Support\Tenancy\TenantContext;
use Illuminate\Validation\ValidationException;

/**
 * Crea una lotería. Por defecto el creador queda como encargado; gestión puede
 * asignarla a otro miembro (asignado_membership_id, validado en el request).
 * La sección debe ser una zona que el encargado asignado tiene asignada.
 */
class CrearLoteria
{
    /**
     * @param  array<string, mixed>  $datos
     *
     * @throws ValidationException si la sección no es una zona del encargado asignado
     */
    public function handle(Membership $creador, array $datos): Loteria
    {
        $seccion = Seccion::findOrFail((int) $datos['seccion_id']);
        $asignado = $this->resolverAsignado($creador, $datos);

        if (! $asignado->puedeCapturarEnSeccion($seccion->id)) {
            throw ValidationException::withMessages([
                'seccion_id' => $asignado->is($creador)
                    ? 'Solo puedes crear lotería en las secciones que tienes asignadas.'
                    : 'El encargado asignado no tiene esa sección en sus zonas.',
            ]);
        }

        return Loteria::create([
            'membership_id' => $asignado->id,
            'creada_por_membership_id' => $creador->id,
            'seccion_id' => $seccion->id,
            'nombre' => $datos['nombre'],
            'fecha' => $datos['fecha'],
        ]);
    }

    /**
     * @param  array<string, mixed>  $datos
     */
    private function resolverAsignado(Membership $creador, array $datos): Membership
    {
        if (empty($datos['asignado_membership_id'])) {
            return $creador;
        }

        return Membership::query()
            ->where('tenant_id', TenantContext::get()?->id)
            ->findOrFail((int) $datos['asignado_membership_id']);
    }
}
