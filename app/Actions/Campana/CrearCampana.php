<?php

namespace App\Actions\Campana;

use App\Models\Tenant;

class CrearCampana
{
    /**
     * Crea una campaña (tenant) sobre un municipio existente del catálogo
     * global. No copia secciones: el municipio_id basta para heredarlas.
     *
     * @param  array{nombre: string, municipio_id: int, plan?: string, limite_brigadistas?: int|null, marca_nombre?: string|null, marca_logo_url?: string|null, marca_color?: string|null, subdominio?: string|null}  $datos
     */
    public function handle(array $datos): Tenant
    {
        return Tenant::create([
            'nombre' => $datos['nombre'],
            'municipio_id' => $datos['municipio_id'],
            'plan' => $datos['plan'] ?? 'basico',
            'limite_brigadistas' => $datos['limite_brigadistas'] ?? null,
            'marca_nombre' => $datos['marca_nombre'] ?? null,
            'marca_logo_url' => $datos['marca_logo_url'] ?? null,
            'marca_color' => $datos['marca_color'] ?? null,
            'subdominio' => $datos['subdominio'] ?? null,
        ]);
    }
}
