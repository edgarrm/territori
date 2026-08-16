<?php

namespace App\Http\Controllers;

use App\Models\EncuestaPregunta;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Toggle de `incluida` para una pregunta ya detectada (F9: columnas nuevas de
 * un canal pull llegan con incluida=false hasta que el coordinador las activa
 * a mano). No toca etiqueta/tipo/opciones/campo.
 */
class EncuestaPreguntaController extends Controller
{
    /**
     * Resolución manual (anti-binding): SubstituteBindings corre antes que
     * ResolveTenant en el stack web.
     */
    public function actualizar(Request $request, string $pregunta): JsonResponse
    {
        $validado = $request->validate(['incluida' => ['required', 'boolean']]);

        $modelo = EncuestaPregunta::query()->findOrFail($pregunta);
        $modelo->update(['incluida' => $validado['incluida']]);

        return response()->json(['ok' => true]);
    }
}
