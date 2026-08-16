<?php

namespace App\Actions\Zonas;

use App\Actions\Zonas\Concerns\ValidaGeometriaZona;
use App\Models\Zona;
use Illuminate\Validation\ValidationException;

/**
 * Actualiza una zona territorial personalizada del tenant activo (F6).
 * Actualización parcial: solo toca los campos presentes en $datos.
 */
class ActualizarZona
{
    use ValidaGeometriaZona;

    /**
     * @param  array<string, mixed>  $datos
     *
     * @throws ValidationException
     */
    public function handle(Zona $zona, array $datos): Zona
    {
        if (array_key_exists('geom', $datos)) {
            // Se valida ANTES de escribir: una geometría inválida no debe
            // tocar la zona existente (mismo criterio que la creación).
            $this->validarGeom($datos['geom']);
        }

        $zona->update([
            'nombre' => $datos['nombre'] ?? $zona->nombre,
            'clave' => array_key_exists('clave', $datos) ? $datos['clave'] : $zona->clave,
            'descripcion' => array_key_exists('descripcion', $datos) ? $datos['descripcion'] : $zona->descripcion,
            'tipo' => $datos['tipo'] ?? $zona->tipo,
            'zona_padre_id' => array_key_exists('zona_padre_id', $datos) ? $datos['zona_padre_id'] : $zona->zona_padre_id,
            'fuente' => $datos['fuente'] ?? $zona->fuente,
            'activa' => array_key_exists('activa', $datos) ? $datos['activa'] : $zona->activa,
        ]);

        if (array_key_exists('geom', $datos)) {
            $this->guardarGeom($zona, $datos['geom']);

            // Solo cuando hay geom: una zona sin polígono (ej. tipo=mundial)
            // no tiene nada que intersectar (F7).
            if ($datos['geom'] !== null) {
                (new CalcularInterseccionZona)->handle($zona);
            }
        }

        return $zona->fresh();
    }
}
