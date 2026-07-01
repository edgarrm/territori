<?php

namespace App\Http\Controllers;

use App\Actions\RedesCiudadanas\CrearRedCiudadana;
use App\Http\Requests\StoreRedCiudadanaRequest;
use App\Models\Elector;
use App\Models\Membership;
use App\Models\RedCiudadana;
use App\Models\Seccion;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class RedCiudadanaController extends Controller
{
    /**
     * Lista de redes: el enlace ve solo las suyas; gestión (coordinador/admin)
     * ve todas y puede crear nuevas asignando enlace.
     */
    public function index(): Response
    {
        $viewer = $this->miMembership();
        $esGestion = $viewer?->esGestion() ?? false;

        $redes = RedCiudadana::query()
            ->with('enlace.user')
            ->withCount('electores')
            ->when(! $esGestion, fn ($query) => $query->where('enlace_membership_id', $viewer?->id))
            ->orderBy('nombre')
            ->get()
            ->map(fn (RedCiudadana $red): array => $this->presentar($red))
            ->all();

        $secciones = Seccion::query()
            ->where('municipio_id', TenantContext::get()?->municipio_id)
            ->orderBy('numero')
            ->get(['id', 'numero'])
            ->map(fn (Seccion $seccion): array => [
                'id' => $seccion->id,
                'numero' => $seccion->numero,
            ])
            ->all();

        // Gestión necesita el catálogo de miembros para designar al enlace
        // (puede ser una membership de cualquier rol).
        $enlaces = $esGestion
            ? Membership::query()
                ->where('tenant_id', TenantContext::get()?->id)
                ->where('activo', true)
                ->with('user')
                ->get()
                ->map(fn (Membership $m): array => [
                    'membership_id' => $m->id,
                    'nombre' => $m->user->name,
                    'rol' => $m->rol,
                ])
                ->all()
            : [];

        return Inertia::render('RedesCiudadanas', [
            'redes' => $redes,
            'secciones' => $secciones,
            'enlaces' => $enlaces,
            'esGestion' => $esGestion,
        ]);
    }

    public function store(StoreRedCiudadanaRequest $request, CrearRedCiudadana $crear): RedirectResponse
    {
        $crear->handle($request->validated());

        return back();
    }

    /**
     * Registros (electores) capturados en una red. Solo su enlace o gestión
     * pueden verlos; por eso todos se devuelven con la PII completa.
     */
    public function registros(string $red): JsonResponse
    {
        $modelo = RedCiudadana::query()->findOrFail($red);
        $viewer = $this->miMembership();

        abort_unless(
            $viewer !== null && ($modelo->enlace_membership_id === $viewer->id || $viewer->esGestion()),
            403,
        );

        $registros = Elector::query()
            ->where('red_ciudadana_id', $modelo->id)
            ->latest()
            ->paginate(50)
            ->through(fn (Elector $elector): array => [
                'id' => $elector->id,
                'nombre' => $elector->nombre,
                'telefono' => $elector->telefono,
                'email' => $elector->email,
                'seccion_id' => $elector->seccion_id,
                'capturado_en' => $elector->created_at?->toIso8601String(),
            ]);

        return response()->json($registros);
    }

    /**
     * @return array<string, mixed>
     */
    private function presentar(RedCiudadana $red): array
    {
        return [
            'id' => $red->id,
            'nombre' => $red->nombre,
            'descripcion' => $red->descripcion,
            'activa' => $red->activa,
            'enlace' => [
                'membership_id' => $red->enlace_membership_id,
                'nombre' => $red->enlace?->user?->name,
                'rol' => $red->enlace?->rol,
            ],
            'registros_count' => $red->electores_count ?? 0,
        ];
    }

    /**
     * Membresía activa del usuario en el tenant actual. El rol vive en la
     * membership, nunca en el user.
     */
    private function miMembership(): ?Membership
    {
        $user = request()->user();
        $tenant = TenantContext::get();

        return ($user !== null && $tenant !== null) ? $user->membershipEn($tenant) : null;
    }
}
