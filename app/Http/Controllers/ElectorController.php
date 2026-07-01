<?php

namespace App\Http\Controllers;

use App\Actions\Electores\ActualizarElector;
use App\Actions\Electores\CapturarElector;
use App\Actions\Privacidad\CancelarElector;
use App\Exceptions\ElectorDuplicado;
use App\Http\Requests\StoreElectorRequest;
use App\Http\Requests\UpdateElectorRequest;
use App\Models\Elector;
use App\Models\Membership;
use App\Models\Seccion;
use App\Support\Pii;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Inertia\Inertia;
use Inertia\Response;

class ElectorController extends Controller
{
    public function store(StoreElectorRequest $request, CapturarElector $capturar): JsonResponse
    {
        $membership = $request->user()->membershipEn(TenantContext::get());

        try {
            $elector = $capturar->handle($membership, $request->validated());
        } catch (ElectorDuplicado $e) {
            return response()->json([
                'message' => 'Ya existe un contacto con ese teléfono en esta campaña.',
            ], 409);
        }

        return response()->json($this->presentar($elector, $membership), 201);
    }

    /**
     * Resolución manual (no binding implícito): SubstituteBindings corre antes
     * que ResolveTenant en el stack web, así que un modelo tenant-scoped bindeado
     * implícitamente se resolvería sin TenantContext. Aquí ya hay tenant activo.
     */
    public function show(string $elector): JsonResponse
    {
        $modelo = Elector::query()->findOrFail($elector);

        return response()->json($this->presentar($modelo, $this->miMembership()));
    }

    public function update(UpdateElectorRequest $request, string $elector, ActualizarElector $actualizar): JsonResponse
    {
        $modelo = Elector::query()->findOrFail($elector);

        try {
            $actualizar->handle($modelo, $request->validated());
        } catch (ElectorDuplicado $e) {
            return response()->json([
                'message' => 'Ya existe un contacto con ese teléfono en esta campaña.',
            ], 409);
        }

        return response()->json($this->presentar($modelo->fresh(), $this->miMembership()));
    }

    /**
     * Cancelación ARCO (ADR-004): baja lógica + scrub de PII. La ruta restringe
     * a coordinador/admin (rol:). Resolución manual del modelo tenant-scoped.
     */
    public function destroy(string $elector, CancelarElector $cancelar): JsonResponse
    {
        $modelo = Elector::query()->findOrFail($elector);

        $cancelar->handle($modelo);

        return response()->json(['message' => 'Elector cancelado (ARCO).']);
    }

    /**
     * Ficha del elector (Inertia): datos editables + timeline de interacciones.
     */
    public function page(string $elector): Response
    {
        $modelo = Elector::query()->with('interacciones')->findOrFail($elector);

        return Inertia::render('Elector', [
            'elector' => $this->presentar($modelo, $this->miMembership()),
            'interacciones' => $modelo->interacciones->map(fn ($i): array => [
                'id' => $i->id,
                'tipo' => $i->tipo,
                'resultado' => $i->resultado,
                'nota' => $i->nota,
                'fecha' => $i->fecha->toIso8601String(),
                'proximo_seguimiento' => $i->proximo_seguimiento?->toDateString(),
                'atendido_en' => $i->atendido_en?->toIso8601String(),
            ])->all(),
        ]);
    }

    public function indexPorSeccion(Seccion $seccion): JsonResponse
    {
        $viewer = $this->miMembership();

        $electores = Elector::query()
            ->where('seccion_id', $seccion->id)
            ->latest()
            ->paginate(50)
            ->through(fn (Elector $elector): array => [
                'id' => $elector->id,
                'nombre' => $elector->nombre,
                'telefono' => $this->puedeVerPii($elector, $viewer)
                    ? $elector->telefono
                    : Pii::enmascararTelefono($elector->telefono),
            ]);

        return response()->json($electores);
    }

    /**
     * Membresía activa del usuario en el tenant actual (para decidir visibilidad
     * de PII). El rol vive en la membership, nunca en el user.
     */
    private function miMembership(): ?Membership
    {
        $user = request()->user();
        $tenant = TenantContext::get();

        return ($user !== null && $tenant !== null) ? $user->membershipEn($tenant) : null;
    }

    /**
     * Gestión (coordinador/admin) ve la PII completa de todos; un brigadista
     * solo la de los electores que él capturó. Cualquier otro caso → enmascarada.
     */
    private function puedeVerPii(Elector $elector, ?Membership $viewer): bool
    {
        if ($viewer === null) {
            return false;
        }

        if (in_array($viewer->rol, ['coordinador', 'admin'], true)) {
            return true;
        }

        // El enlace ve la PII completa de todos los registros de sus redes,
        // sin importar quién los capturó.
        if ($elector->red_ciudadana_id !== null
            && $elector->redCiudadana?->enlace_membership_id === $viewer->id) {
            return true;
        }

        return $elector->membership_id === $viewer->id;
    }

    /**
     * @return array<string, mixed>
     */
    private function presentar(Elector $elector, ?Membership $viewer): array
    {
        $verPii = $this->puedeVerPii($elector, $viewer);

        return [
            'id' => $elector->id,
            'seccion_id' => $elector->seccion_id,
            'modo_captura' => $elector->modo_captura,
            'nombre' => $elector->nombre,
            'telefono' => $verPii ? $elector->telefono : Pii::enmascararTelefono($elector->telefono),
            'email' => $verPii ? $elector->email : Pii::enmascararEmail($elector->email),
            'domicilio' => $verPii ? $elector->domicilio : Pii::enmascararDomicilio($elector->domicilio),
            'observaciones' => $elector->observaciones,
            'consentimiento' => $elector->consentimiento,
        ];
    }
}
