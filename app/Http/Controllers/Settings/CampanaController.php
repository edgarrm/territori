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

        $tenant?->update([
            'partido_id' => $request->validated('partido_id'),
            'settings' => [
                'umbral_ganada_franca' => $request->validated('umbral_ganada_franca'),
                'umbral_alfa' => $request->validated('umbral_alfa'),
                'umbral_beta' => $request->validated('umbral_beta'),
                'indicadores' => $request->validated('indicadores'),
            ],
        ]);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Campaign configuration updated.')]);

        return to_route('campana.edit');
    }
}
