<?php

namespace App\Http\Controllers;

use App\Actions\Brigadistas\AsignarZonas;
use App\Actions\Campana\InvitarMiembro;
use App\Http\Requests\StoreBrigadistaRequest;
use App\Models\Membership;
use App\Models\Seccion;
use App\Support\Brigadistas\RatiosBrigadista;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

class BrigadistaController extends Controller
{
    public function index(RatiosBrigadista $ratios): Response
    {
        $tenant = TenantContext::get();

        $secciones = Seccion::query()
            ->where('municipio_id', $tenant?->municipio_id)
            ->orderBy('numero')
            ->get(['id', 'numero'])
            ->map(fn (Seccion $s): array => ['id' => $s->id, 'numero' => $s->numero]);

        // Miembros que no son brigadistas (coordinadores, enlaces, admins,
        // anfitriones): la tabla de brigadistas lleva ratios que no aplican a
        // estos roles. El anfitrión sí lleva zonas (acotan sus loterías).
        $otrosMiembros = Membership::query()
            ->where('tenant_id', $tenant?->id)
            ->where('rol', '!=', 'brigadista')
            ->with(['user:id,name,email', 'secciones'])
            ->orderBy('rol')
            ->get()
            ->map(fn (Membership $m): array => [
                'membership_id' => $m->id,
                'nombre' => $m->user->name,
                'email' => $m->user->email,
                'rol' => $m->rol,
                'activo' => $m->activo,
                'secciones_ids' => $m->esAnfitrion() ? $m->secciones->pluck('id')->all() : [],
            ])
            ->all();

        return Inertia::render('Brigadistas', [
            'brigadistas' => $ratios->paraTenant($tenant),
            'otrosMiembros' => $otrosMiembros,
            'rolesAsignables' => $this->miMembership()?->rolesQuePuedeAsignar() ?? [],
            'secciones' => $secciones,
            'facturacion' => [
                'activos' => $tenant->brigadistasActivosCount(),
                'limite' => $tenant->limite_brigadistas,
                'puede_activar' => $tenant->puedeActivarBrigadista(),
            ],
        ]);
    }

    /**
     * Membresía activa del usuario en el tenant actual.
     */
    private function miMembership(): ?Membership
    {
        $user = request()->user();
        $tenant = TenantContext::get();

        return ($user !== null && $tenant !== null) ? $user->membershipEn($tenant) : null;
    }

    public function store(StoreBrigadistaRequest $request, InvitarMiembro $invitar, AsignarZonas $asignar): JsonResponse|RedirectResponse
    {
        $tenant = TenantContext::get();
        $seccionIds = $request->validated('seccion_ids', []);

        try {
            // Alta y zonas en una transacción: si las secciones no son válidas
            // (AsignarZonas lanza ValidationException), no queda un miembro sin
            // sus zonas (estado parcial).
            DB::transaction(function () use ($invitar, $asignar, $tenant, $request, $seccionIds): void {
                $miembro = $invitar->handle($tenant, [
                    'email' => $request->validated('email'),
                    'name' => $request->validated('name'),
                    'rol' => $request->validated('rol'),
                    'meta_diaria' => $request->validated('meta_diaria'),
                    'activo' => $request->boolean('activo', true),
                ]);

                if ($seccionIds !== [] && ($miembro->esBrigadista() || $miembro->esAnfitrion())) {
                    $asignar->handle($miembro, $seccionIds);
                }
            });
        } catch (ValidationException $e) {
            // Secciones fuera del municipio: la transacción ya revirtió el alta.
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['ok' => true], 201);
    }

    public function activo(Request $request, string $membership): JsonResponse
    {
        $brigadista = $this->resolverBrigadista($membership);
        $activo = $request->boolean('activo');

        try {
            $activo ? $brigadista->activar() : $brigadista->desactivar();
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['activo' => $brigadista->fresh()->activo]);
    }

    public function zonas(Request $request, string $membership, AsignarZonas $asignar): JsonResponse
    {
        $miembro = $this->resolverConZonas($membership);

        $data = $request->validate([
            'seccion_ids' => ['present', 'array'],
            'seccion_ids.*' => ['integer'],
        ]);

        $asignar->handle($miembro, $data['seccion_ids']);

        return response()->json(['ok' => true]);
    }

    public function ratios(string $membership, RatiosBrigadista $ratios): JsonResponse
    {
        $brigadista = $this->resolverBrigadista($membership);

        return response()->json($ratios->paraMembership($brigadista));
    }

    /**
     * Resolución manual tenant-scoped (memberships NO usa global scope, y
     * SubstituteBindings corre antes que ResolveTenant). Un brigadista de otro
     * tenant → 404 por el where('tenant_id').
     */
    private function resolverBrigadista(string $id): Membership
    {
        return Membership::query()
            ->where('tenant_id', TenantContext::get()?->id)
            ->where('rol', 'brigadista')
            ->findOrFail($id);
    }

    /**
     * Miembros con zonas asignables: brigadistas y anfitriones (las zonas
     * acotan dónde capturan/crean loterías). Misma resolución tenant-scoped.
     */
    private function resolverConZonas(string $id): Membership
    {
        return Membership::query()
            ->where('tenant_id', TenantContext::get()?->id)
            ->whereIn('rol', ['brigadista', 'anfitrion'])
            ->findOrFail($id);
    }
}
