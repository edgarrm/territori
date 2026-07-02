<?php

namespace App\Http\Controllers;

use App\Actions\Eventos\CrearEvento;
use App\Http\Controllers\Concerns\PresentaElectores;
use App\Http\Requests\StoreEventoRequest;
use App\Models\Elector;
use App\Models\Evento;
use App\Models\Seccion;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class EventoController extends Controller
{
    use PresentaElectores;

    public function index(): Response
    {
        $viewer = request()->user()?->membershipEn(TenantContext::get());

        // Catálogo del alta: todas las secciones del municipio (la sede puede
        // ser cualquiera). El filtro usa las secciones disponibles por rol.
        $secciones = Seccion::query()
            ->where('municipio_id', TenantContext::get()?->municipio_id)
            ->orderBy('numero')
            ->get(['id', 'numero'])
            ->map(fn (Seccion $seccion): array => [
                'id' => $seccion->id,
                'numero' => $seccion->numero,
            ])
            ->all();

        return Inertia::render('Eventos', [
            'secciones' => $secciones,
            'seccionesFiltro' => $viewer?->seccionesDisponibles() ?? [],
        ]);
    }

    /**
     * Lista paginada de eventos para el cliente. Filtros opcionales: `q`
     * (nombre del evento, ILIKE) y `secciones` (arreglo de ids de sección/sede,
     * del multiselect de secciones disponibles por rol).
     */
    public function data(Request $request): JsonResponse
    {
        $busqueda = trim((string) $request->query('q', ''));
        $secciones = array_values(array_filter(array_map(
            'intval',
            (array) $request->query('secciones', []),
        )));

        $eventos = Evento::query()
            ->withCount('electores')
            ->when(
                $busqueda !== '',
                fn (Builder $query) => $query->where('nombre', 'ILIKE', '%'.$busqueda.'%'),
            )
            ->when(
                $secciones !== [],
                fn (Builder $query) => $query->whereIn('seccion_id', $secciones),
            )
            ->orderByDesc('fecha')
            ->paginate(15)
            ->withQueryString()
            ->through(fn (Evento $e): array => $this->presentar($e));

        return response()->json($eventos);
    }

    public function store(StoreEventoRequest $request, CrearEvento $crear): RedirectResponse
    {
        $crear->handle($request->validated());

        return back();
    }

    /**
     * Asistentes (electores capturados) de un evento, como lista paginada estándar
     * de capturados. Resolución manual del modelo tenant-scoped (patrón
     * anti-binding Sprint 4).
     */
    public function asistentes(Request $request, string $evento): JsonResponse
    {
        $modelo = Evento::query()->findOrFail($evento);

        $asistentes = $this->paginarElectores(
            Elector::query()->where('evento_id', $modelo->id),
            $request,
            $request->user()?->membershipEn(TenantContext::get()),
        );

        return response()->json($asistentes);
    }

    /**
     * @return array<string, mixed>
     */
    private function presentar(Evento $evento): array
    {
        return [
            'id' => $evento->id,
            'nombre' => $evento->nombre,
            'tipo' => $evento->tipo,
            'fecha' => $evento->fecha->toIso8601String(),
            'lugar' => $evento->lugar,
            'seccion_id' => $evento->seccion_id,
            'asistentes_count' => $evento->electores_count ?? null,
        ];
    }
}
