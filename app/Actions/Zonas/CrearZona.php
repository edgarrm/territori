<?php

namespace App\Actions\Zonas;

use App\Actions\Zonas\Concerns\ValidaGeometriaZona;
use App\Models\Zona;
use Illuminate\Validation\ValidationException;

/**
 * Crea una zona territorial personalizada del tenant activo (F6). Solo
 * GeoJSON en v1 (sin conversión de Shapefile/KML en runtime). No restringe la
 * geometría al municipio del tenant a propósito (decisión de la spec).
 */
class CrearZona
{
    use ValidaGeometriaZona;

    /**
     * @param  array<string, mixed>  $datos
     *
     * @throws ValidationException
     */
    public function handle(array $datos, ?int $creadoPorMembershipId): Zona
    {
        // Se valida ANTES de crear: una geometría inválida no debe persistir
        // nada (criterio de aceptación 2).
        $this->validarGeom($datos['geom'] ?? null);

        $zona = Zona::create([
            'nombre' => $datos['nombre'],
            'clave' => $datos['clave'] ?? null,
            'descripcion' => $datos['descripcion'] ?? null,
            'tipo' => $datos['tipo'],
            'zona_padre_id' => $datos['zona_padre_id'] ?? null,
            'fuente' => $datos['fuente'] ?? 'dibujada',
            'activa' => $datos['activa'] ?? true,
            'creado_por_membership_id' => $creadoPorMembershipId,
        ]);

        $this->guardarGeom($zona, $datos['geom'] ?? null);

        return $zona->fresh();
    }
}
