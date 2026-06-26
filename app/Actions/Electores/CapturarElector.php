<?php

namespace App\Actions\Electores;

use App\Events\ElectorCapturado;
use App\Exceptions\ElectorDuplicado;
use App\Models\Elector;
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
        $seccion = $this->resolverSeccion($modo, $datos, $loteria, $membership);

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
     * @param  array<string, mixed>  $datos
     */
    private function resolverSeccion(string $modo, array $datos, ?Loteria $loteria, Membership $membership): Seccion
    {
        if ($modo === 'loteria') {
            return Seccion::findOrFail($loteria->seccion_id);
        }

        if (! empty($datos['seccion_id'])) {
            return Seccion::findOrFail((int) $datos['seccion_id']);
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
