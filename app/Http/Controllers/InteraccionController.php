<?php

namespace App\Http\Controllers;

use App\Actions\Electores\VerificarElector;
use App\Actions\Interacciones\RegistrarInteraccion;
use App\Http\Requests\StoreInteraccionRequest;
use App\Models\Elector;
use App\Models\Interaccion;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
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

        $this->asegurarAccesoElector($modelo);

        return response()->json(
            $modelo->interacciones()->get()->map(fn (Interaccion $i): array => $this->presentar($i))->all()
        );
    }

    public function store(StoreInteraccionRequest $request, string $elector, RegistrarInteraccion $registrar, VerificarElector $verificar): JsonResponse
    {
        $modelo = Elector::query()->findOrFail($elector);
        $membership = $request->user()->membershipEn(TenantContext::get());

        abort_unless($membership !== null && $membership->puedeAccederElector($modelo), 403);

        $interaccion = $registrar->handle($modelo, $request->validated(), $membership);

        if ($request->boolean('verificar')) {
            $verificar->marcar($modelo, $interaccion->tipo, $membership);
        }

        return response()->json($this->presentar($interaccion), 201);
    }

    /**
     * Marca un seguimiento como atendido (idempotente: conserva la primera marca).
     */
    public function atendido(string $interaccion): JsonResponse
    {
        $modelo = Interaccion::query()->with('elector.redCiudadana')->findOrFail($interaccion);

        $this->asegurarAccesoElector($modelo->elector);

        if ($modelo->atendido_en === null) {
            $modelo->update(['atendido_en' => now()]);
        }

        return response()->json($this->presentar($modelo));
    }

    /**
     * Agenda del día: seguimientos vencidos no atendidos. La lista se carga desde
     * el cliente vía agendaData (paginada + búsqueda), como en el detalle de sección.
     */
    public function agenda(): Response
    {
        $viewer = request()->user()?->membershipEn(TenantContext::get());

        return Inertia::render('Agenda', [
            'secciones' => $viewer?->seccionesDisponibles() ?? [],
        ]);
    }

    public function agendaData(Request $request): JsonResponse
    {
        return response()->json($this->pendientes($request));
    }

    /**
     * Seguimientos vencidos no atendidos, paginados. Brigadista ve los suyos;
     * coordinador/admin ven todos los del tenant (rol desde la membership).
     * Filtros opcionales: `q` (nombre del elector, ILIKE) y `secciones` (arreglo
     * de ids de sección, del multiselect de secciones accesibles).
     *
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    private function pendientes(Request $request): LengthAwarePaginator
    {
        $membership = $request->user()->membershipEn(TenantContext::get());

        $busqueda = trim((string) $request->query('q', ''));
        $secciones = array_values(array_filter(array_map(
            'intval',
            (array) $request->query('secciones', []),
        )));

        return Interaccion::query()
            ->pendientes()
            ->with(['elector:id,nombre,seccion_id', 'elector.seccion:id,numero'])
            ->when(
                $membership->esBrigadista(),
                fn (Builder $query) => $query->where('membership_id', $membership->id),
            )
            ->when(
                $busqueda !== '',
                fn (Builder $query) => $query->whereHas(
                    'elector',
                    fn (Builder $elector) => $elector->where('nombre', 'ILIKE', '%'.$busqueda.'%'),
                ),
            )
            ->when(
                $secciones !== [],
                fn (Builder $query) => $query->whereHas(
                    'elector',
                    fn (Builder $elector) => $elector->whereIn('seccion_id', $secciones),
                ),
            )
            ->orderBy('proximo_seguimiento')
            ->paginate(25)
            ->withQueryString()
            ->through(fn (Interaccion $i): array => [
                'id' => $i->id,
                'tipo' => $i->tipo,
                'nota' => $i->nota,
                'proximo_seguimiento' => $i->proximo_seguimiento?->toDateString(),
                'elector' => [
                    'id' => $i->elector->id,
                    'nombre' => $i->elector->nombre,
                    'seccion_id' => $i->elector->seccion_id,
                    'seccion_numero' => $i->elector->seccion?->numero,
                ],
            ]);
    }

    /**
     * Autoriza el acceso al timeline de un elector según las zonas/propiedad del
     * viewer (misma regla que la ficha del elector). 403 si no le corresponde.
     * El elector puede ser null si fue cancelado (ARCO, soft-delete): en ese caso
     * solo gestión puede operar sobre sus interacciones huérfanas.
     */
    private function asegurarAccesoElector(?Elector $elector): void
    {
        $viewer = request()->user()?->membershipEn(TenantContext::get());

        abort_if($viewer === null, 403);

        if ($elector === null) {
            abort_unless($viewer->esGestion(), 403);

            return;
        }

        abort_unless($viewer->puedeAccederElector($elector), 403);
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
