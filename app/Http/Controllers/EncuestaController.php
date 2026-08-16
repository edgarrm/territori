<?php

namespace App\Http\Controllers;

use App\Actions\Encuestas\ActualizarEncuesta;
use App\Actions\Encuestas\CrearEncuesta;
use App\Http\Requests\StoreEncuestaRequest;
use App\Http\Requests\UpdateEncuestaRequest;
use App\Models\Encuesta;
use App\Models\EncuestaPregunta;
use App\Models\Seccion;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class EncuestaController extends Controller
{
    public function index(): Response
    {
        $encuestas = Encuesta::query()
            ->with('preguntas')
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (Encuesta $encuesta): array => $this->presentar($encuesta))
            ->all();

        return Inertia::render('Encuestas', [
            'encuestas' => $encuestas,
        ]);
    }

    public function store(StoreEncuestaRequest $request, CrearEncuesta $crear): RedirectResponse
    {
        $tenant = TenantContext::get();
        $membership = $tenant ? $request->user()?->membershipEn($tenant) : null;

        $crear->handle($request->validated(), $membership?->id);

        return back();
    }

    /**
     * Resolución manual (anti-binding): SubstituteBindings corre antes que
     * ResolveTenant en el stack web.
     */
    public function update(UpdateEncuestaRequest $request, string $encuesta, ActualizarEncuesta $actualizar): RedirectResponse
    {
        $modelo = Encuesta::query()->findOrFail($encuesta);

        $actualizar->handle($modelo, $request->validated());

        return back();
    }

    /**
     * Página de captura para el flujo brigadista (autenticado).
     */
    public function captura(string $encuesta): Response
    {
        $modelo = Encuesta::query()->findOrFail($encuesta);

        return Inertia::render('EncuestaCaptura', [
            'encuesta' => $this->presentarParaCaptura($modelo),
            'secciones' => $modelo->ambito_tipo === 'seccion'
                ? Seccion::query()
                    ->where('municipio_id', TenantContext::get()?->municipio_id)
                    ->orderBy('numero')
                    ->get(['id', 'numero'])
                    ->map(fn (Seccion $s): array => ['id' => $s->id, 'numero' => $s->numero])
                    ->all()
                : [],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function presentar(Encuesta $encuesta): array
    {
        return [
            'id' => $encuesta->id,
            'nombre' => $encuesta->nombre,
            'canal' => $encuesta->canal->value,
            'ambito_tipo' => $encuesta->ambito_tipo,
            'modo_acceso' => $encuesta->modo_acceso,
            'token_publico' => $encuesta->token_publico,
            'estado' => $encuesta->estado,
            'preguntas' => $encuesta->preguntas->map(fn (EncuestaPregunta $p): array => [
                'id' => $p->id,
                'etiqueta' => $p->etiqueta,
                'tipo' => $p->tipo,
                'opciones' => $p->opciones,
                'incluida' => $p->incluida,
                'orden' => $p->orden,
            ])->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function presentarParaCaptura(Encuesta $encuesta): array
    {
        return [
            'id' => $encuesta->id,
            'nombre' => $encuesta->nombre,
            'ambito_tipo' => $encuesta->ambito_tipo,
            'preguntas' => $encuesta->preguntas()
                ->where('incluida', true)
                ->get()
                ->map(fn (EncuestaPregunta $p): array => [
                    'id' => $p->id,
                    'etiqueta' => $p->etiqueta,
                    'tipo' => $p->tipo,
                    'opciones' => $p->opciones,
                ])->all(),
        ];
    }
}
