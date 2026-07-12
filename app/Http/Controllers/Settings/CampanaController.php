<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\CampanaUpdateRequest;
use App\Models\Partido;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class CampanaController extends Controller
{
    /**
     * Configuración del análisis electoral de la campaña activa (solo admin).
     */
    public function edit(): Response
    {
        $tenant = TenantContext::get();

        return Inertia::render('settings/Campana', [
            'partidos' => Partido::query()->orderBy('nombre')->get(['id', 'siglas', 'nombre', 'color']),
            'partidoId' => $tenant?->partido_id,
            'configuracion' => $tenant?->configuracion(),
        ]);
    }

    /**
     * Guarda partido y settings del análisis electoral de la campaña activa.
     */
    public function update(CampanaUpdateRequest $request): RedirectResponse
    {
        $tenant = TenantContext::get();

        $categorias = $request->validated('categorias_competitividad');
        // Defensa: el catch-all (última fila) nunca debe persistir un umbral,
        // sin importar lo que haya llegado deshabilitado desde el formulario.
        $categorias[array_key_last($categorias)]['umbral'] = null;

        $tenant?->update([
            'partido_id' => $request->validated('partido_id'),
            'settings' => [
                'umbral_alfa' => $request->validated('umbral_alfa'),
                'umbral_beta' => $request->validated('umbral_beta'),
                'modo_calculo_competitividad' => $request->validated('modo_calculo_competitividad'),
                'categorias_competitividad' => $categorias,
                'indicadores' => $request->validated('indicadores'),
            ],
        ]);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Campaign configuration updated.')]);

        return to_route('campana.edit');
    }
}
