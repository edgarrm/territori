<?php

namespace App\Http\Controllers;

use App\Actions\Campana\RegistrarCampana;
use App\Http\Requests\StoreCampanaRequest;
use App\Models\Entidad;
use App\Models\Municipio;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class CampanaController extends Controller
{
    /**
     * Form de alta de campaña. Sirve el catálogo geográfico (entidad ->
     * municipio) para el select en cascada.
     */
    public function create(): Response
    {
        $entidades = Entidad::query()
            ->with(['municipios:id,entidad_id,nombre'])
            ->orderBy('nombre')
            ->get(['id', 'nombre'])
            ->map(fn (Entidad $entidad): array => [
                'id' => $entidad->id,
                'nombre' => $entidad->nombre,
                'municipios' => $entidad->municipios
                    ->sortBy('nombre')
                    ->map(fn (Municipio $municipio): array => [
                        'id' => $municipio->id,
                        'nombre' => $municipio->nombre,
                    ])
                    ->values()
                    ->all(),
            ]);

        return Inertia::render('campanas/Crear', [
            'entidades' => $entidades,
        ]);
    }

    public function store(StoreCampanaRequest $request, RegistrarCampana $registrar): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        $tenant = $registrar->handle($user, $request->datosCampana());

        $request->session()->put('tenant_id', $tenant->id);

        return redirect()->route('dashboard');
    }
}
