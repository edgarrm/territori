<?php

namespace App\Support\Brigadistas;

use App\Models\Membership;
use App\Models\Tenant;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * @phpstan-type RatiosResumen array{capturas_dia: int, capturas_total: int, pct_completos: float, avance_meta: float|null, secciones_asignadas: int}
 * @phpstan-type BrigadistaConRatios array{membership_id: int, nombre: string, email: string, activo: bool, meta_diaria: int|null, capturas_dia: int, capturas_total: int, pct_completos: float, avance_meta: float|null, secciones_asignadas: int, secciones_ids: list<int>}
 */
class RatiosBrigadista
{
    /**
     * Ratios de un brigadista: productividad (capturas_dia/total),
     * calidad (pct_completos = con telefono_hash) y avance de meta diaria.
     *
     * @return RatiosResumen
     */
    public function paraMembership(Membership $brigadista): array
    {
        $agg = $this->agregadosElectores($brigadista->tenant_id, $brigadista->id)->get($brigadista->id);
        $ids = $this->seccionesIdsPorMembership($brigadista->tenant_id, $brigadista->id)->get($brigadista->id, []);

        return $this->construir($brigadista, $agg, count($ids));
    }

    /**
     * Ratios de todos los brigadistas del tenant, en una sola pasada (sin N+1).
     *
     * @return list<BrigadistaConRatios>
     */
    public function paraTenant(Tenant $tenant): array
    {
        $agg = $this->agregadosElectores($tenant->id);
        $idsPorBrigadista = $this->seccionesIdsPorMembership($tenant->id);

        $filas = Membership::query()
            ->where('tenant_id', $tenant->id)
            ->where('rol', 'brigadista')
            ->with('user:id,name,email')
            ->get()
            ->map(function (Membership $b) use ($agg, $idsPorBrigadista): array {
                $ids = $idsPorBrigadista->get($b->id, []);
                $r = $this->construir($b, $agg->get($b->id), count($ids));

                return [
                    'membership_id' => $b->id,
                    'nombre' => $b->user->name,
                    'email' => $b->user->email,
                    'activo' => $b->activo,
                    'meta_diaria' => $b->meta_diaria,
                    'capturas_dia' => $r['capturas_dia'],
                    'capturas_total' => $r['capturas_total'],
                    'pct_completos' => $r['pct_completos'],
                    'avance_meta' => $r['avance_meta'],
                    'secciones_asignadas' => $r['secciones_asignadas'],
                    'secciones_ids' => $ids,
                ];
            })
            ->all();

        return array_values($filas);
    }

    /**
     * @return RatiosResumen
     */
    private function construir(Membership $brigadista, ?stdClass $agg, int $asignadas): array
    {
        $total = (int) ($agg->capturas_total ?? 0);
        $dia = (int) ($agg->capturas_dia ?? 0);
        $completos = (int) ($agg->completos ?? 0);

        return [
            'capturas_dia' => $dia,
            'capturas_total' => $total,
            'pct_completos' => $total > 0 ? (float) ($completos / $total) : 0.0,
            'avance_meta' => $brigadista->meta_diaria ? (float) ($dia / $brigadista->meta_diaria) : null,
            'secciones_asignadas' => $asignadas,
        ];
    }

    /**
     * Una query agregada sobre electores, agrupada por membership_id.
     * Cada fila es un stdClass con capturas_total, capturas_dia y completos.
     *
     * @return Collection<int|string, stdClass>
     */
    private function agregadosElectores(int $tenantId, ?int $membershipId = null): Collection
    {
        return DB::table('electores')
            ->where('tenant_id', $tenantId)
            ->when($membershipId !== null, fn ($q) => $q->where('membership_id', $membershipId))
            ->groupBy('membership_id')
            ->selectRaw(
                'membership_id,
                COUNT(*) as capturas_total,
                COUNT(*) FILTER (WHERE created_at >= ?) as capturas_dia,
                COUNT(*) FILTER (WHERE telefono_hash IS NOT NULL) as completos',
                [now()->startOfDay()],
            )
            ->get()
            ->keyBy('membership_id');
    }

    /**
     * Secciones asignadas por brigadista (pivote tenant-scoped por columna).
     *
     * @return Collection<int|string, list<int>>
     */
    private function seccionesIdsPorMembership(int $tenantId, ?int $membershipId = null): Collection
    {
        return DB::table('brigadista_seccion')
            ->where('tenant_id', $tenantId)
            ->when($membershipId !== null, fn ($q) => $q->where('membership_id', $membershipId))
            ->get(['membership_id', 'seccion_id'])
            ->groupBy('membership_id')
            ->map(fn (Collection $filas): array => array_values($filas->pluck('seccion_id')->map(fn ($id): int => (int) $id)->all()));
    }
}
