<?php

namespace App\Http\Controllers;

use App\Actions\Encuestas\TabularRespuesta;
use App\Models\Concerns\TenantScope;
use App\Models\Encuesta;
use App\Models\EncuestaPregunta;
use App\Models\Seccion;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Primera ruta de escritura pública del sistema (F8): sin login, fuera de
 * `auth`/`tenant`, dentro del `web` middleware base. `tenant_id` se resuelve
 * SIEMPRE del `tenant_id` de la Encuesta encontrada por token, nunca del
 * request — única excepción documentada a la regla de `_ai/CONTEXT.md`.
 *
 * `resolverPorToken()` es el único punto de todo el sistema donde se hace
 * `withoutGlobalScope(TenantScope::class)`: la búsqueda por token es, por
 * diseño, entre tenants (no hay sesión ni tenant activo todavía). En cuanto
 * se resuelve la encuesta, `TenantContext::set()` fija el tenant real (mismo
 * patrón que los jobs/comandos de sistema) y el resto de queries usa el scope
 * normal.
 */
class EncuestaPublicaController extends Controller
{
    public function show(string $token): Response
    {
        $encuesta = $this->resolverPorToken($token);
        TenantContext::set($encuesta->tenant);

        return Inertia::render('EncuestaPublica', [
            'token' => $token,
            'nombre' => $encuesta->nombre,
            'ambito_tipo' => $encuesta->ambito_tipo,
            'secciones' => $encuesta->ambito_tipo === 'seccion'
                ? Seccion::query()
                    ->where('municipio_id', $encuesta->tenant->municipio_id)
                    ->orderBy('numero')
                    ->get(['id', 'numero'])
                    ->map(fn (Seccion $s): array => ['id' => $s->id, 'numero' => $s->numero])
                    ->all()
                : [],
            'preguntas' => $encuesta->preguntas()
                ->where('incluida', true)
                ->get()
                ->map(fn (EncuestaPregunta $p): array => [
                    'id' => $p->id,
                    'etiqueta' => $p->etiqueta,
                    'tipo' => $p->tipo,
                    'opciones' => $p->opciones,
                ])->all(),
        ]);
    }

    public function store(Request $request, string $token, TabularRespuesta $tabular): JsonResponse
    {
        $encuesta = $this->resolverPorToken($token);
        TenantContext::set($encuesta->tenant);

        $validado = $request->validate([
            'ambito_id' => ['required', 'integer', $this->reglaAmbito($encuesta)],
            'respuestas' => ['required', 'array', 'min:1'],
            'respuestas.*' => ['required', 'string'],
        ]);

        $this->validarOpciones($encuesta, $validado['respuestas']);

        $tabular->handle($encuesta, $encuesta->ambito_tipo, (int) $validado['ambito_id'], $validado['respuestas']);

        return response()->json(['ok' => true], 201);
    }

    /**
     * `ambito_id` llega sin validar de un cliente anónimo: confirma que
     * referencia una sección/zona real y en el ámbito de este tenant antes de
     * dejarla llegar a `TabularRespuesta` (si no, se tabulan ámbitos
     * inventados o de otro tenant).
     */
    private function reglaAmbito(Encuesta $encuesta): Exists
    {
        return $encuesta->ambito_tipo === 'seccion'
            ? Rule::exists('secciones', 'id')->where('municipio_id', $encuesta->tenant->municipio_id)
            : Rule::exists('zonas', 'id')->where('tenant_id', $encuesta->tenant_id);
    }

    private function resolverPorToken(string $token): Encuesta
    {
        $encuesta = Encuesta::withoutGlobalScope(TenantScope::class)
            ->where('token_publico', $token)
            ->whereIn('modo_acceso', ['publico', 'ambos'])
            ->first();

        abort_if($encuesta === null, 404);

        return $encuesta;
    }

    /**
     * @param  array<int|string, string>  $respuestas
     */
    private function validarOpciones(Encuesta $encuesta, array $respuestas): void
    {
        $preguntas = $encuesta->preguntas()->get()->keyBy('id');

        foreach ($respuestas as $preguntaId => $opcion) {
            $pregunta = $preguntas->get((int) $preguntaId);

            if ($pregunta === null) {
                throw ValidationException::withMessages([
                    'respuestas' => 'Una de las preguntas no pertenece a esta encuesta.',
                ]);
            }

            if ($pregunta->tipo !== 'opcion_unica') {
                continue;
            }

            $opcionesValidas = $pregunta->opciones ?? [];

            if (! in_array($opcion, $opcionesValidas, true)) {
                throw ValidationException::withMessages([
                    "respuestas.{$preguntaId}" => 'La opción no pertenece al catálogo de esta pregunta.',
                ]);
            }
        }
    }
}
