<?php

namespace App\Http\Controllers;

use App\Actions\Loterias\CrearLoteria;
use App\Http\Controllers\Concerns\PresentaElectores;
use App\Http\Requests\StoreLoteriaRequest;
use App\Models\Elector;
use App\Models\Loteria;
use App\Models\Membership;
use App\Models\Seccion;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class LoteriaController extends Controller
{
    use PresentaElectores;

    /**
     * Lista de loterías: gestión (coordinador/admin) ve todas; el resto solo
     * las que creó o tiene asignadas.
     */
    public function index(): Response
    {
        $viewer = $this->membership();
        $esGestion = $viewer->esGestion();

        $loterias = Loteria::query()
            ->with(['membership.user', 'creadaPor.user'])
            ->withCount('electores')
            ->when(! $esGestion, fn ($query) => $query->where(
                fn ($q) => $q->where('membership_id', $viewer->id)
                    ->orWhere('creada_por_membership_id', $viewer->id),
            ))
            ->orderByDesc('fecha')
            ->get()
            ->map(fn (Loteria $loteria): array => $this->presentar($loteria))
            ->all();

        // El select de sección solo ofrece las secciones a las que el viewer
        // tiene acceso: gestión todas; brigadista/anfitrión sus zonas.
        $secciones = ($esGestion
            ? Seccion::query()->where('municipio_id', TenantContext::get()?->municipio_id)
            : $viewer->secciones())
            ->orderBy('numero')
            ->get(['secciones.id', 'numero'])
            ->map(fn (Seccion $seccion): array => [
                'id' => $seccion->id,
                'numero' => $seccion->numero,
            ])
            ->all();

        // Gestión necesita el catálogo de miembros para asignar la lotería a
        // un encargado distinto (espejo del catálogo de enlaces de redes).
        $miembros = $esGestion
            ? Membership::query()
                ->where('tenant_id', TenantContext::get()?->id)
                ->where('activo', true)
                ->with('user')
                ->get()
                ->map(fn (Membership $m): array => [
                    'membership_id' => $m->id,
                    'nombre' => $m->user->name,
                    'rol' => $m->rol,
                ])
                ->all()
            : [];

        return Inertia::render('Loterias', [
            'loterias' => $loterias,
            'secciones' => $secciones,
            'miembros' => $miembros,
            'esGestion' => $esGestion,
        ]);
    }

    public function store(StoreLoteriaRequest $request, CrearLoteria $crear): RedirectResponse
    {
        $crear->handle($this->membership(), $request->validated());

        return back();
    }

    /**
     * Electores capturados en una lotería, como lista paginada estándar de
     * capturados (resolución manual tenant-scoped).
     */
    public function electores(Request $request, string $loteria): JsonResponse
    {
        $modelo = Loteria::query()->findOrFail($loteria);

        $electores = $this->paginarElectores(
            Elector::query()->where('loteria_id', $modelo->id),
            $request,
            $this->membership(),
        );

        return response()->json($electores);
    }

    /**
     * @return array<string, mixed>
     */
    private function presentar(Loteria $loteria): array
    {
        return [
            'id' => $loteria->id,
            'nombre' => $loteria->nombre,
            'fecha' => $loteria->fecha->toIso8601String(),
            'seccion_id' => $loteria->seccion_id,
            'encargado' => $loteria->membership?->user?->name,
            'creador' => $loteria->creadaPor?->user?->name,
            'capturados_count' => $loteria->electores_count ?? 0,
        ];
    }

    private function membership(): Membership
    {
        $tenant = TenantContext::get();
        $membership = $tenant ? request()->user()?->membershipEn($tenant) : null;

        abort_if($membership === null, 403);

        return $membership;
    }
}
