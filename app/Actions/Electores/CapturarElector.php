<?php

namespace App\Actions\Electores;

use App\Events\ElectorCapturado;
use App\Exceptions\ElectorDuplicado;
use App\Models\Elector;
use App\Models\Evento;
use App\Models\Loteria;
use App\Models\Membership;
use App\Models\Seccion;
use App\Support\Telefono;
use App\Support\Tenancy\TenantContext;
use Illuminate\Validation\ValidationException;
use MatanYadaev\EloquentSpatial\Enums\Srid;
use MatanYadaev\EloquentSpatial\Objects\Point;

/**
 * Orquesta una captura de elector (lotería o individual): resuelve sección,
 * deduplica por telefono_hash dentro del tenant, exige consentimiento y dispara
 * ElectorCapturado. Nunca toma tenant_id ni rol del request.
 */
class CapturarElector
{
    /**
     * @param  array<string, mixed>  $datos
     *
     * @throws ElectorDuplicado si ya hay un elector con ese teléfono en el tenant
     * @throws ValidationException si no se puede resolver la sección
     */
    public function handle(Membership $membership, array $datos): Elector
    {
        $modo = $datos['modo_captura'];
        $loteria = $this->resolverLoteria($modo, $datos, $membership);
        $evento = $this->resolverEvento($modo, $datos);
        $seccion = $this->resolverSeccion($modo, $datos, $loteria, $evento, $membership);

        $telefonoHash = Telefono::hash($datos['telefono']);

        $existente = Elector::query()->where('telefono_hash', $telefonoHash)->first();

        if ($existente !== null) {
            throw new ElectorDuplicado($existente);
        }

        $ubicacion = isset($datos['ubicacion'])
            ? new Point((float) $datos['ubicacion']['lat'], (float) $datos['ubicacion']['lng'], Srid::WGS84)
            : null;

        $elector = Elector::create([
            'seccion_id' => $seccion->id,
            'membership_id' => $membership->id,
            'modo_captura' => $modo,
            'loteria_id' => $loteria?->id,
            'evento_id' => $evento?->id,
            'nombre' => $datos['nombre'],
            'telefono' => $datos['telefono'],
            'telefono_hash' => $telefonoHash,
            'domicilio' => $datos['domicilio'] ?? null,
            'ubicacion' => $ubicacion,
            'observaciones' => $datos['observaciones'] ?? null,
            'consentimiento' => true,
            'aviso_privacidad_id' => $datos['aviso_privacidad_id'],
        ]);

        ElectorCapturado::dispatch($elector->tenant_id, $seccion->id);

        return $elector;
    }

    /**
     * @param  array<string, mixed>  $datos
     */
    private function resolverLoteria(string $modo, array $datos, Membership $membership): ?Loteria
    {
        if ($modo !== 'loteria') {
            return null;
        }

        $loteria = Loteria::query()
            ->where('membership_id', $membership->id)
            ->whereNull('cerrada_en')
            ->latest('abierta_en')
            ->first();

        if ($loteria === null) {
            throw ValidationException::withMessages([
                'loteria_id' => 'No hay una lotería abierta para capturar en este modo.',
            ]);
        }

        return $loteria;
    }

    /**
     * Resuelve el evento para el modo 'evento'. El evento debe ser del tenant
     * activo (resolución tenant-scoped, nunca un evento ajeno).
     *
     * @param  array<string, mixed>  $datos
     */
    private function resolverEvento(string $modo, array $datos): ?Evento
    {
        if ($modo !== 'evento') {
            return null;
        }

        if (empty($datos['evento_id'])) {
            throw ValidationException::withMessages([
                'evento_id' => 'Indica el evento para capturar en este modo.',
            ]);
        }

        $evento = Evento::query()->find((int) $datos['evento_id']);

        if ($evento === null) {
            throw ValidationException::withMessages([
                'evento_id' => 'El evento no existe en esta campaña.',
            ]);
        }

        return $evento;
    }

    /**
     * @param  array<string, mixed>  $datos
     */
    private function resolverSeccion(string $modo, array $datos, ?Loteria $loteria, ?Evento $evento, Membership $membership): Seccion
    {
        if ($modo === 'loteria') {
            return Seccion::findOrFail($loteria->seccion_id);
        }

        // Evento con sede definida: la sección se hereda del evento.
        if ($modo === 'evento' && $evento?->seccion_id !== null) {
            return Seccion::findOrFail($evento->seccion_id);
        }

        if (! empty($datos['seccion_id'])) {
            $seccion = Seccion::findOrFail((int) $datos['seccion_id']);

            if ($seccion->municipio_id !== TenantContext::get()?->municipio_id) {
                throw ValidationException::withMessages([
                    'seccion_id' => 'La sección no pertenece al municipio de la campaña.',
                ]);
            }

            return $seccion;
        }

        if (isset($datos['ubicacion'])) {
            $municipioId = TenantContext::get()?->municipio_id;
            $punto = new Point((float) $datos['ubicacion']['lat'], (float) $datos['ubicacion']['lng'], Srid::WGS84);

            $seccion = Seccion::query()
                ->where('municipio_id', $municipioId)
                ->whereContains('geom', $punto)
                ->first();

            if ($seccion !== null) {
                return $seccion;
            }

            throw ValidationException::withMessages([
                'ubicacion' => 'La ubicación no cae dentro de ninguna sección del municipio.',
            ]);
        }

        throw ValidationException::withMessages([
            'seccion_id' => 'Indica la sección o una ubicación GPS válida.',
        ]);
    }
}
