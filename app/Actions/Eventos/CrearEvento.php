<?php

namespace App\Actions\Eventos;

use App\Models\Evento;
use App\Models\Seccion;
use App\Support\Tenancy\TenantContext;
use Illuminate\Validation\ValidationException;
use MatanYadaev\EloquentSpatial\Enums\Srid;
use MatanYadaev\EloquentSpatial\Objects\Point;

/**
 * Crea un evento en el tenant activo. Si trae sección sede, valida que sea del
 * municipio del tenant (nunca una sección ajena).
 */
class CrearEvento
{
    /**
     * @param  array<string, mixed>  $datos
     *
     * @throws ValidationException si la sección no pertenece al municipio del tenant
     */
    public function handle(array $datos): Evento
    {
        $seccionId = empty($datos['seccion_id']) ? null : (int) $datos['seccion_id'];

        if ($seccionId !== null) {
            $municipioId = TenantContext::get()?->municipio_id;
            $valida = Seccion::query()
                ->where('id', $seccionId)
                ->where('municipio_id', $municipioId)
                ->exists();

            if (! $valida) {
                throw ValidationException::withMessages([
                    'seccion_id' => 'La sección no pertenece al municipio de la campaña.',
                ]);
            }
        }

        $ubicacion = isset($datos['ubicacion'])
            ? new Point((float) $datos['ubicacion']['lat'], (float) $datos['ubicacion']['lng'], Srid::WGS84)
            : null;

        return Evento::create([
            'nombre' => $datos['nombre'],
            'tipo' => $datos['tipo'],
            'fecha' => $datos['fecha'],
            'lugar' => $datos['lugar'] ?? null,
            'seccion_id' => $seccionId,
            'ubicacion' => $ubicacion,
        ]);
    }
}
