<?php

namespace App\Http\Controllers;

use App\Actions\Eventos\CrearEvento;
use App\Http\Requests\StoreEventoRequest;
use App\Models\Elector;
use App\Models\Evento;
use App\Models\Seccion;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class EventoController extends Controller
{
    public function index(): Response
    {
        $eventos = Evento::query()
            ->withCount('electores')
            ->orderByDesc('fecha')
            ->get()
            ->map(fn (Evento $e): array => $this->presentar($e))
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

        return Inertia::render('Eventos', [
            'eventos' => $eventos,
            'secciones' => $secciones,
        ]);
    }

    public function store(StoreEventoRequest $request, CrearEvento $crear): RedirectResponse
    {
        $crear->handle($request->validated());

        return back();
    }

    /**
     * Asistentes (electores capturados) de un evento. Resolución manual del
     * modelo tenant-scoped (patrón anti-binding Sprint 4).
     */
    public function asistentes(string $evento): JsonResponse
    {
        $modelo = Evento::query()->findOrFail($evento);

        $asistentes = Elector::query()
            ->where('evento_id', $modelo->id)
            ->latest()
            ->get()
            ->map(fn (Elector $el): array => [
                'id' => $el->id,
                'nombre' => $el->nombre,
                'seccion_id' => $el->seccion_id,
                'capturado_en' => $el->created_at?->toIso8601String(),
            ])
            ->all();

        return response()->json([
            'evento' => $this->presentar($modelo),
            'asistentes' => $asistentes,
        ]);
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
