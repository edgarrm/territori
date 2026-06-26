<?php

namespace App\Http\Controllers;

use App\Actions\Loterias\AbrirLoteria;
use App\Models\Loteria;
use App\Models\Membership;
use App\Models\Seccion;
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
