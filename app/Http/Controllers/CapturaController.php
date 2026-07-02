<?php

namespace App\Http\Controllers;

use App\Models\Evento;
use App\Models\Loteria;
use App\Models\Seccion;
use App\Support\Tenancy\TenantContext;
use Inertia\Inertia;
use Inertia\Response;

class CapturaController extends Controller
{
    public function index(): Response
    {
        $tenant = TenantContext::get();
        $membership = $tenant ? request()->user()?->membershipEn($tenant) : null;

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

        // Loterías donde puede capturar: gestión ve todas; el brigadista las
        // que él encabeza.
        $loterias = Loteria::query()
            ->when(
                $membership !== null && ! $membership->esGestion(),
                fn ($query) => $query->where('membership_id', $membership?->id),
            )
            ->orderByDesc('fecha')
            ->get(['id', 'nombre', 'fecha', 'seccion_id'])
            ->map(fn (Loteria $loteria): array => [
                'id' => $loteria->id,
                'nombre' => $loteria->nombre,
                'fecha' => $loteria->fecha->toDateString(),
                'seccion_id' => $loteria->seccion_id,
            ]);

        return Inertia::render('Captura', [
            'secciones' => $secciones,
            'eventos' => $eventos,
            'loterias' => $loterias,
        ]);
    }
}
