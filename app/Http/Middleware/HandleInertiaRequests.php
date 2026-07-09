<?php

namespace App\Http\Middleware;

use App\Models\Membership;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $user = $request->user();
        $tenant = TenantContext::get();
        $membership = $tenant && $user instanceof User ? $user->membershipEn($tenant) : null;

        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'auth' => [
                'user' => $request->user(),
                'rol' => $membership?->rol,
                // Zonas del brigadista (para acotar el mapa en el front); vacío
                // para otros roles. La restricción real la impone el backend.
                'secciones_asignadas' => $membership?->esBrigadista()
                    ? $membership->secciones()->pluck('secciones.id')->all()
                    : [],
                'tenant' => $tenant?->nombre,
                'tenant_id' => $tenant?->id,
                'campanas' => $user instanceof User
                    ? $user->memberships()->where('activo', true)->with('tenant')->get()
                        ->map(fn (Membership $m): array => [
                            'tenant_id' => $m->tenant_id,
                            'nombre' => $m->tenant->marca_nombre ?? $m->tenant->nombre,
                            'rol' => $m->rol,
                        ])->values()
                    : [],
            ],
            'marca' => [
                'nombre' => $tenant === null ? 'Territori' : ($tenant->marca_nombre ?? 'Territori'),
                'color' => $tenant === null ? '#1d4ed8' : ($tenant->marca_color ?? '#1d4ed8'),
                'logo_url' => $tenant?->marca_logo_url,
            ],
            // Configuración efectiva del análisis electoral (F1): partido +
            // umbrales + toggles, para que Mapa/Prioridades/Seccion (F2/F3)
            // la consuman sin fetch extra.
            'campana' => $tenant === null ? null : [
                'partido' => $tenant->partido?->only(['id', 'siglas', 'nombre', 'color']),
                ...$tenant->configuracion(),
            ],
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
        ];
    }
}
