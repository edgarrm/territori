<?php

namespace App\Http\Controllers;

use App\Models\Elector;
use App\Models\Evento;
use App\Models\Interaccion;
use App\Models\Membership;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function index(Request $request): Response
    {
        $tenant = TenantContext::get();
        $user = $request->user();
        $membership = $tenant && $user instanceof User ? $user->membershipEn($tenant) : null;

        return Inertia::render('Dashboard', [
            'rol' => $membership?->rol,
            'kpis' => $membership !== null && $membership->esBrigadista()
                ? $this->kpisBrigadista($membership, $tenant)
                : $this->kpisCampana($tenant),
            'eventosProximos' => $this->eventosProximos(),
            'seguimientosPendientes' => $this->seguimientosPendientes($membership),
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
     * KPIs propios del brigadista: sus capturas y zonas asignadas.
     *
     * @return array<int, array{clave: string, label: string, valor: int, sufijo?: string}>
     */
    private function kpisBrigadista(Membership $membership, ?Tenant $tenant): array
    {
        $misCapturados = Elector::query()->where('membership_id', $membership->id)->count();
        $hoy = Elector::query()->where('membership_id', $membership->id)->whereDate('created_at', today())->count();

        $idsSecciones = $membership->secciones()->pluck('secciones.id');
        $misSecciones = $idsSecciones->count();

        $metaZonas = (int) DB::table('cobertura_seccion')
            ->where('tenant_id', $tenant?->id)
            ->whereIn('seccion_id', $idsSecciones)
            ->sum('meta');

        return [
            ['clave' => 'mis_capturados', 'label' => 'Mis capturados', 'valor' => $misCapturados],
            ['clave' => 'hoy', 'label' => 'Capturados hoy', 'valor' => $hoy],
            ['clave' => 'mi_avance', 'label' => 'Mi avance', 'valor' => $this->porcentaje($misCapturados, $metaZonas), 'sufijo' => '%'],
            ['clave' => 'mis_secciones', 'label' => 'Mis secciones', 'valor' => $misSecciones],
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
                'fecha' => $evento->fecha?->toDateString(),
                'lugar' => $evento->lugar,
            ])
            ->all();
    }

    /**
     * Conteo de seguimientos pendientes; acotado al brigadista si aplica.
     */
    private function seguimientosPendientes(?Membership $membership): int
    {
        $query = Interaccion::query()->pendientes();

        if ($membership !== null && $membership->esBrigadista()) {
            $query->where('membership_id', $membership->id);
        }

        return $query->count();
    }

    private function porcentaje(int $parte, int $total): int
    {
        return $total > 0 ? (int) round($parte / $total * 100) : 0;
    }
}
