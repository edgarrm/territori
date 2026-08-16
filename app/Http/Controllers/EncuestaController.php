<?php

namespace App\Http\Controllers;

use App\Actions\Encuestas\ActualizarEncuesta;
use App\Actions\Encuestas\CrearEncuesta;
use App\Actions\Encuestas\SincronizarEncuesta;
use App\Exceptions\ConexionGoogleInvalida;
use App\Exceptions\ConexionGoogleNoEncontrada;
use App\Http\Requests\StoreEncuestaRequest;
use App\Http\Requests\UpdateEncuestaRequest;
use App\Models\Encuesta;
use App\Models\EncuestaPregunta;
use App\Models\Seccion;
use App\Support\Encuestas\ResolverFuenteEncuesta;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

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
     * Verifica que la encuesta puede leer su fuente externa ahora mismo
     * (canal pull, ej. Google Sheets). No sincroniza nada.
     *
     * Resolución manual (anti-binding): SubstituteBindings corre antes que
     * ResolveTenant en el stack web.
     */
    public function verificarAcceso(string $encuesta, ResolverFuenteEncuesta $resolver): JsonResponse
    {
        $modelo = Encuesta::query()->findOrFail($encuesta);

        $fuente = $resolver->resolver($modelo);

        if ($fuente === null) {
            return response()->json(['error' => 'Este canal no soporta verificación de acceso.'], 422);
        }

        try {
            return response()->json($fuente->verificarAcceso($modelo));
        } catch (ConexionGoogleNoEncontrada|ConexionGoogleInvalida $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }
    }

    /**
     * Sincroniza una encuesta de canal pull, síncrono en el request (F9: sin
     * jobs de cola ni scheduler en v1).
     */
    public function sincronizar(string $encuesta, SincronizarEncuesta $sincronizar): JsonResponse
    {
        $modelo = Encuesta::query()->findOrFail($encuesta);

        try {
            $resultado = $sincronizar->handle($modelo);

            return response()->json($resultado);
        } catch (ConexionGoogleNoEncontrada|ConexionGoogleInvalida $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        } catch (RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }
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
            'configuracion_canal' => $encuesta->configuracion_canal,
            'ambito_tipo' => $encuesta->ambito_tipo,
            'modo_acceso' => $encuesta->modo_acceso,
            'token_publico' => $encuesta->token_publico,
            'estado' => $encuesta->estado,
            'ultima_sincronizacion' => $encuesta->ultima_sincronizacion?->toIso8601String(),
            'ultimo_resultado' => $encuesta->ultimo_resultado,
            'preguntas' => $encuesta->preguntas->map(fn (EncuestaPregunta $p): array => [
                'id' => $p->id,
                'campo' => $p->campo,
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
