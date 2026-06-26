<?php

namespace App\Http\Controllers;

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

        return Inertia::render('Captura', ['secciones' => $secciones]);
    }
}
