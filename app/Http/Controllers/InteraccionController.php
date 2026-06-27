<?php

namespace App\Http\Controllers;

use App\Actions\Interacciones\RegistrarInteraccion;
use App\Http\Requests\StoreInteraccionRequest;
use App\Models\Elector;
use App\Models\Interaccion;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class InteraccionController extends Controller
{
    /**
     * Timeline de un elector (desc por fecha). Resolución manual: el binding
     * implícito correría antes de ResolveTenant (patrón Sprint 4).
     */
    public function indexPorElector(string $elector): JsonResponse
    {
        $modelo = Elector::query()->findOrFail($elector);

        return response()->json(
            $modelo->interacciones()->get()->map(fn (Interaccion $i): array => $this->presentar($i))->all()
        );
    }

    public function store(StoreInteraccionRequest $request, string $elector, RegistrarInteraccion $registrar): JsonResponse
    {
        $modelo = Elector::query()->findOrFail($elector);
        $membership = $request->user()->membershipEn(TenantContext::get());

        $interaccion = $registrar->handle($modelo, $request->validated(), $membership);

        return response()->json($this->presentar($interaccion), 201);
    }

    /**
     * Marca un seguimiento como atendido (idempotente: conserva la primera marca).
     */
    public function atendido(string $interaccion): JsonResponse
    {
        $modelo = Interaccion::query()->findOrFail($interaccion);

        if ($modelo->atendido_en === null) {
            $modelo->update(['atendido_en' => now()]);
        }

        return response()->json($this->presentar($modelo));
    }

    /**
     * Agenda del día: seguimientos vencidos no atendidos. Brigadista ve los
     * suyos; coordinador/admin ven todos los del tenant (rol desde la membership).
     */
    public function agenda(Request $request): Response
    {
        return Inertia::render('Agenda', [
            'pendientes' => $this->pendientes($request),
        ]);
    }

    public function agendaData(Request $request): JsonResponse
    {
        return response()->json($this->pendientes($request));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function pendientes(Request $request): array
    {
        $membership = $request->user()->membershipEn(TenantContext::get());

        $query = Interaccion::query()
            ->pendientes()
            ->with('elector:id,nombre,seccion_id')
            ->orderBy('proximo_seguimiento');

        if ($membership->esBrigadista()) {
            $query->where('membership_id', $membership->id);
        }

        return $query->get()->map(fn (Interaccion $i): array => [
            'id' => $i->id,
            'tipo' => $i->tipo,
            'nota' => $i->nota,
            'proximo_seguimiento' => $i->proximo_seguimiento?->toDateString(),
            'elector' => [
                'id' => $i->elector->id,
                'nombre' => $i->elector->nombre,
                'seccion_id' => $i->elector->seccion_id,
            ],
        ])->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function presentar(Interaccion $interaccion): array
    {
        return [
            'id' => $interaccion->id,
            'elector_id' => $interaccion->elector_id,
            'membership_id' => $interaccion->membership_id,
            'tipo' => $interaccion->tipo,
            'resultado' => $interaccion->resultado,
            'nota' => $interaccion->nota,
            'fecha' => $interaccion->fecha->toIso8601String(),
            'proximo_seguimiento' => $interaccion->proximo_seguimiento?->toDateString(),
            'atendido_en' => $interaccion->atendido_en?->toIso8601String(),
        ];
    }
}
