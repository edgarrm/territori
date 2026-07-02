<?php

namespace App\Http\Controllers;

use App\Actions\Electores\ActualizarElector;
use App\Actions\Electores\CapturarElector;
use App\Actions\Privacidad\CancelarElector;
use App\Exceptions\ElectorDuplicado;
use App\Http\Controllers\Concerns\PresentaElectores;
use App\Http\Requests\StoreElectorRequest;
use App\Http\Requests\UpdateElectorRequest;
use App\Models\Elector;
use App\Models\Membership;
use App\Models\Seccion;
use App\Support\Pii;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ElectorController extends Controller
{
    use PresentaElectores;

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

    /**
     * Vista global de capturados (solo gestión, restringida por ruta). Expone
     * los catálogos para los filtros: secciones del municipio (para mapear
     * números en la tabla), secciones disponibles del rol y tipos de captura.
     */
    public function index(): Response
    {
        $viewer = $this->miMembership();

        $secciones = Seccion::query()
            ->where('municipio_id', TenantContext::get()?->municipio_id)
            ->orderBy('numero')
            ->get(['id', 'numero'])
            ->map(fn (Seccion $seccion): array => [
                'id' => $seccion->id,
                'numero' => $seccion->numero,
            ])
            ->all();

        return Inertia::render('Capturados', [
            'secciones' => $secciones,
            'seccionesFiltro' => $viewer?->seccionesDisponibles() ?? [],
            'modos' => $this->catalogoModos(),
        ]);
    }

    /**
     * Lista paginada de capturados para el cliente. Filtros opcionales: `q`
     * (nombre, ILIKE), `modos` (arreglo de tipos de captura) y `secciones`
     * (arreglo de ids de sección). El global scope de tenant acota al tenant
     * activo; la ruta ya restringe a gestión (ve todo el municipio).
     */
    public function data(Request $request): JsonResponse
    {
        $viewer = $this->miMembership();

        $electores = $this->paginarElectores(Elector::query(), $request, $viewer);

        return response()->json($electores);
    }

    public function indexPorSeccion(Request $request, Seccion $seccion): JsonResponse
    {
        $viewer = $this->miMembership();

        // El brigadista solo consulta electores de sus zonas asignadas.
        abort_if($viewer !== null && ! $viewer->puedeCapturarEnSeccion($seccion->id), 403);

        $electores = $this->paginarElectores(
            Elector::query()->where('seccion_id', $seccion->id),
            $request,
            $viewer,
        );

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
