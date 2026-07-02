<?php

namespace App\Http\Controllers;

use App\Actions\Loterias\CrearLoteria;
use App\Http\Requests\StoreLoteriaRequest;
use App\Models\Elector;
use App\Models\Loteria;
use App\Models\Membership;
use App\Models\Seccion;
use App\Support\Pii;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class LoteriaController extends Controller
{
    /**
     * Lista de loterías: gestión (coordinador/admin) ve todas; el brigadista
     * solo las que él encabeza.
     */
    public function index(): Response
    {
        $viewer = $this->membership();
        $esGestion = $viewer->esGestion();

        $loterias = Loteria::query()
            ->with('membership.user')
            ->withCount('electores')
            ->when(! $esGestion, fn ($query) => $query->where('membership_id', $viewer->id))
            ->orderByDesc('fecha')
            ->get()
            ->map(fn (Loteria $loteria): array => $this->presentar($loteria))
            ->all();

        $secciones = Seccion::query()
            ->where('municipio_id', TenantContext::get()?->municipio_id)
            ->orderBy('numero')
            ->get(['id', 'numero'])
            ->map(fn (Seccion $seccion): array => [
                'id' => $seccion->id,
                'numero' => $seccion->numero,
            ])
            ->all();

        return Inertia::render('Loterias', [
            'loterias' => $loterias,
            'secciones' => $secciones,
        ]);
    }

    public function store(StoreLoteriaRequest $request, CrearLoteria $crear): RedirectResponse
    {
        $crear->handle($this->membership(), $request->validated());

        return back();
    }

    /**
     * Electores capturados en una lotería (resolución manual tenant-scoped).
     */
    public function electores(string $loteria): JsonResponse
    {
        $modelo = Loteria::query()->findOrFail($loteria);
        $viewer = $this->membership();

        $electores = Elector::query()
            ->where('loteria_id', $modelo->id)
            ->latest()
            ->get()
            ->map(fn (Elector $elector): array => [
                'id' => $elector->id,
                'nombre' => $elector->nombre,
                'telefono' => $this->puedeVerPii($elector, $viewer)
                    ? $elector->telefono
                    : Pii::enmascararTelefono($elector->telefono),
                'capturado_en' => $elector->created_at?->toIso8601String(),
            ]);

        return response()->json([
            'electores' => $electores,
            'total' => $electores->count(),
        ]);
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
            'capturados_count' => $loteria->electores_count ?? 0,
        ];
    }

    /**
     * Gestión (coordinador/admin) ve la PII completa; un brigadista solo la de
     * los electores que él capturó. Espejo de ElectorController::puedeVerPii.
     */
    private function puedeVerPii(Elector $elector, Membership $viewer): bool
    {
        if ($viewer->esGestion()) {
            return true;
        }

        return $elector->membership_id === $viewer->id;
    }

    private function membership(): Membership
    {
        $tenant = TenantContext::get();
        $membership = $tenant ? request()->user()?->membershipEn($tenant) : null;

        abort_if($membership === null, 403);

        return $membership;
    }
}
