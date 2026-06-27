<?php

namespace App\Actions\Brigadistas;

use App\Models\Membership;
use App\Models\Seccion;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class AsignarZonas
{
    /**
     * Asigna (reemplaza) las secciones de un brigadista. Idempotente vía sync.
     * Valida que cada sección pertenezca al municipio del tenant activo y
     * persiste el tenant_id en el pivote (memberships no tiene global scope).
     *
     * @param  array<int, int>  $seccionIds
     *
     * @throws ValidationException si alguna sección no es del municipio del tenant.
     */
    public function handle(Membership $brigadista, array $seccionIds): void
    {
        $tenant = TenantContext::get();

        if ($tenant === null || $brigadista->tenant_id !== $tenant->id || ! $brigadista->esBrigadista()) {
            throw new RuntimeException('La membresía no es un brigadista del tenant activo.');
        }

        $ids = collect($seccionIds)->map(fn ($id): int => (int) $id)->unique()->values();

        $delMunicipio = Seccion::query()
            ->whereIn('id', $ids)
            ->where('municipio_id', $tenant->municipio_id)
            ->count();

        if ($delMunicipio !== $ids->count()) {
            throw ValidationException::withMessages([
                'seccion_ids' => 'Una o más secciones no pertenecen al municipio de la campaña.',
            ]);
        }

        $brigadista->secciones()->sync($this->payloadConTenant($ids, $tenant->id));
    }

    /**
     * @param  Collection<int, int>  $ids
     * @return array<int, array{tenant_id: int}>
     */
    private function payloadConTenant(Collection $ids, int $tenantId): array
    {
        return $ids->mapWithKeys(fn (int $id): array => [$id => ['tenant_id' => $tenantId]])->all();
    }
}
