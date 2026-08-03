<?php

namespace App\Http\Controllers;

use App\Models\Evento;
use App\Models\Interaccion;
use App\Models\Membership;
use App\Models\Tenant;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function index(): Response
    {
        $tenant = TenantContext::get();

        return Inertia::render('Dashboard', [
            'kpis' => $this->kpisCampana($tenant),
            'eventosProximos' => $this->eventosProximos(),
            'seguimientosPendientes' => $this->seguimientosPendientes(),
        ]);
    }

    /**
     * KPIs de campaña (admin / coordinador): totales sobre todas las secciones.
     *
     * @return array<int, array{clave: string, label: string, valor: int, sufijo?: string}>
     */
    private function kpisCampana(?Tenant $tenant): array
    {
        $totales = DB::table('cobertura_seccion')
            ->where('tenant_id', $tenant?->id)
            ->selectRaw('COALESCE(SUM(capturados), 0) as capturados, COALESCE(SUM(meta), 0) as meta')
            ->first();

        $capturados = (int) ($totales->capturados ?? 0);
        $meta = (int) ($totales->meta ?? 0);

        $brigadistas = Membership::query()
            ->where('tenant_id', $tenant?->id)
            ->where('rol', 'brigadista')
            ->where('activo', true)
            ->count();

        return [
            ['clave' => 'capturados', 'label' => 'Capturados', 'valor' => $capturados],
            ['clave' => 'meta', 'label' => 'Meta total', 'valor' => $meta],
            ['clave' => 'avance', 'label' => 'Avance global', 'valor' => $this->porcentaje($capturados, $meta), 'sufijo' => '%'],
            ['clave' => 'brigadistas', 'label' => 'Brigadistas activos', 'valor' => $brigadistas],
        ];
    }

    /**
     * Próximos eventos de la campaña (a partir de hoy).
     *
     * @return array<int, array{id: int, nombre: string, tipo: string, fecha: string|null, lugar: string|null}>
     */
    private function eventosProximos(): array
    {
        return Evento::query()
            ->whereDate('fecha', '>=', today())
            ->orderBy('fecha')
            ->limit(5)
            ->get()
            ->map(fn (Evento $evento): array => [
                'id' => $evento->id,
                'nombre' => $evento->nombre,
                'tipo' => $evento->tipo,
                'fecha' => $evento->fecha->toDateString(),
                'lugar' => $evento->lugar,
            ])
            ->all();
    }

    /**
     * Conteo de seguimientos pendientes de toda la campaña.
     */
    private function seguimientosPendientes(): int
    {
        return Interaccion::query()->pendientes()->count();
    }

    private function porcentaje(int $parte, int $total): int
    {
        return $total > 0 ? (int) round($parte / $total * 100) : 0;
    }
}
