<?php

namespace App\Http\Controllers;

use App\Actions\Campana\CerrarCampana;
use App\Actions\Campana\RegistrarCampana;
use App\Http\Requests\StoreCampanaRequest;
use App\Models\Entidad;
use App\Models\Municipio;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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

    /**
     * Cierra la campaña activa: se agota la finalidad del tratamiento y arranca
     * el plazo de retención tras el cual se borra la PII de sus electores
     * (LFPDPPP art. 11). Es irreversible desde la app, por eso la ruta exige
     * admin + 2FA + reconfirmar contraseña.
     */
    public function cerrar(Request $request, CerrarCampana $cerrar): RedirectResponse
    {
        $tenant = TenantContext::get();
        abort_if($tenant === null, 403);

        $cerrar->handle($tenant);

        // La campaña cerrada ya no resuelve como tenant activo en el siguiente
        // request; se saca de la sesión para no dejarla colgando.
        $request->session()->forget('tenant_id');

        return redirect()->route('campanas.seleccionar');
    }
}
