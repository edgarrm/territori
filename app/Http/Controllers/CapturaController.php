<?php

namespace App\Http\Controllers;

use App\Models\Evento;
use App\Models\Seccion;
use App\Support\Tenancy\TenantContext;
use Inertia\Inertia;
use Inertia\Response;

class CapturaController extends Controller
{
    public function index(): Response
    {
        $tenant = TenantContext::get();

        $secciones = Seccion::query()
            ->where('municipio_id', $tenant?->municipio_id)
            ->orderBy('numero')
            ->get(['id', 'numero'])
            ->map(fn (Seccion $seccion): array => [
                'id' => $seccion->id,
                'numero' => $seccion->numero,
            ]);

        $eventos = Evento::query()
            ->orderByDesc('fecha')
            ->get(['id', 'nombre', 'fecha', 'seccion_id'])
            ->map(fn (Evento $evento): array => [
                'id' => $evento->id,
                'nombre' => $evento->nombre,
                'fecha' => $evento->fecha->toDateString(),
                'seccion_id' => $evento->seccion_id,
            ]);

        return Inertia::render('Captura', [
            'secciones' => $secciones,
            'eventos' => $eventos,
        ]);
    }
}
