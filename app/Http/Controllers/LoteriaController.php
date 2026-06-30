<?php

namespace App\Http\Controllers;

use App\Actions\Loterias\AbrirLoteria;
use App\Models\Elector;
use App\Models\Loteria;
use App\Models\Membership;
use App\Models\Seccion;
use App\Support\Pii;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LoteriaController extends Controller
{
    public function store(Request $request, AbrirLoteria $abrir): JsonResponse
    {
        $membership = $this->membership($request);

        $validado = $request->validate([
            'seccion_id' => ['required', 'integer', 'exists:secciones,id'],
        ]);

        $seccion = Seccion::findOrFail((int) $validado['seccion_id']);
        $loteria = $abrir->handle($membership, $seccion);

        return response()->json([
            'loteria_id' => $loteria->id,
            'seccion_id' => $loteria->seccion_id,
        ], 201);
    }

    /**
     * Resolución manual (tenant-scoped): ver nota en ElectorController::show.
     */
    public function cerrar(string $loteria): JsonResponse
    {
        $modelo = Loteria::query()->findOrFail($loteria);
        $modelo->cerrar();

        return response()->json(['cerrada_en' => $modelo->cerrada_en]);
    }

    /**
     * Electores capturados en una lotería (resolución manual tenant-scoped).
     */
    public function electores(Request $request, string $loteria): JsonResponse
    {
        $modelo = Loteria::query()->findOrFail($loteria);
        $viewer = $this->membership($request);

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
     * Gestión (coordinador/admin) ve la PII completa; un brigadista solo la de
     * los electores que él capturó. Espejo de ElectorController::puedeVerPii.
     */
    private function puedeVerPii(Elector $elector, Membership $viewer): bool
    {
        if (in_array($viewer->rol, ['coordinador', 'admin'], true)) {
            return true;
        }

        return $elector->membership_id === $viewer->id;
    }

    public function activa(Request $request): JsonResponse
    {
        $membership = $this->membership($request);

        $loteria = Loteria::query()
            ->where('membership_id', $membership->id)
            ->whereNull('cerrada_en')
            ->latest('abierta_en')
            ->first();

        return response()->json([
            'loteria' => $loteria === null ? null : [
                'loteria_id' => $loteria->id,
                'seccion_id' => $loteria->seccion_id,
                'abierta_en' => $loteria->abierta_en,
            ],
        ]);
    }

    private function membership(Request $request): Membership
    {
        $tenant = TenantContext::get();
        $membership = $tenant ? $request->user()?->membershipEn($tenant) : null;

        abort_if($membership === null, 403);

        return $membership;
    }
}
