<?php

namespace App\Actions\Electores;

use App\Events\ElectorCapturado;
use App\Exceptions\ElectorDuplicado;
use App\Models\Elector;
use App\Models\Evento;
use App\Models\Loteria;
use App\Models\Membership;
use App\Models\RedCiudadana;
use App\Models\Seccion;
use App\Support\Telefono;
use App\Support\Tenancy\TenantContext;
use Illuminate\Validation\ValidationException;
use MatanYadaev\EloquentSpatial\Enums\Srid;
use MatanYadaev\EloquentSpatial\Objects\Point;

/**
 * Orquesta una captura de elector (lotería o enlace seccional): resuelve sección,
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
        $loteria = $this->resolverLoteria($modo, $datos);
        $evento = $this->resolverEvento($modo, $datos);
        $red = $this->resolverRedCiudadana($modo, $datos, $membership);
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
            'red_ciudadana_id' => $red?->id,
            'nombre' => $datos['nombre'],
            'telefono' => $datos['telefono'],
            'telefono_hash' => $telefonoHash,
            'email' => $datos['email'] ?? null,
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
    private function resolverLoteria(string $modo, array $datos): ?Loteria
    {
        if ($modo !== 'loteria') {
            return null;
        }

        if (empty($datos['loteria_id'])) {
            throw ValidationException::withMessages([
                'loteria_id' => 'Indica la lotería para capturar en este modo.',
            ]);
        }

        $loteria = Loteria::query()->find((int) $datos['loteria_id']);

        if ($loteria === null) {
            throw ValidationException::withMessages([
                'loteria_id' => 'La lotería no existe en esta campaña.',
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
     * Resuelve la red ciudadana para el modo 'red_ciudadana'. La red debe ser del
     * tenant activo y la membership que captura debe ser su enlace (nunca una red
     * ajena). La sección se resuelve aparte (una red no tiene sede fija).
     *
     * @param  array<string, mixed>  $datos
     */
    private function resolverRedCiudadana(string $modo, array $datos, Membership $membership): ?RedCiudadana
    {
        if ($modo !== 'red_ciudadana') {
            return null;
        }

        if (empty($datos['red_ciudadana_id'])) {
            throw ValidationException::withMessages([
                'red_ciudadana_id' => 'Indica la red ciudadana para capturar en este modo.',
            ]);
        }

        $red = RedCiudadana::query()->find((int) $datos['red_ciudadana_id']);

        if ($red === null) {
            throw ValidationException::withMessages([
                'red_ciudadana_id' => 'La red ciudadana no existe en esta campaña.',
            ]);
        }

        // Solo el enlace de la red (o gestión) puede capturar en ella.
        if ($red->enlace_membership_id !== $membership->id && ! $membership->esGestion()) {
            throw ValidationException::withMessages([
                'red_ciudadana_id' => 'No eres el enlace de esta red ciudadana.',
            ]);
        }

        if (! $red->activa) {
            throw ValidationException::withMessages([
                'red_ciudadana_id' => 'Esta red ciudadana está inactiva.',
            ]);
        }

        return $red;
    }

    /**
     * Resuelve la sección y, para el brigadista, exige que sea una de sus zonas
     * asignadas (aplica a todos los modos). Gestión no está acotada a zonas.
     *
     * @param  array<string, mixed>  $datos
     */
    private function resolverSeccion(string $modo, array $datos, ?Loteria $loteria, ?Evento $evento, Membership $membership): Seccion
    {
        $seccion = $this->resolverSeccionCruda($modo, $datos, $loteria, $evento, $membership);

        if (! $membership->puedeCapturarEnSeccion($seccion->id)) {
            throw ValidationException::withMessages([
                'seccion_id' => 'Solo puedes capturar en las secciones que tienes asignadas.',
            ]);
        }

        return $seccion;
    }

    /**
     * @param  array<string, mixed>  $datos
     */
    private function resolverSeccionCruda(string $modo, array $datos, ?Loteria $loteria, ?Evento $evento, Membership $membership): Seccion
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
