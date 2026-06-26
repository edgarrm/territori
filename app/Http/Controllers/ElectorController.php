<?php

namespace App\Http\Controllers;

use App\Actions\Electores\CapturarElector;
use App\Exceptions\ElectorDuplicado;
use App\Http\Requests\StoreElectorRequest;
use App\Models\Elector;
use App\Models\Seccion;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;

class ElectorController extends Controller
{
    public function store(StoreElectorRequest $request, CapturarElector $capturar): JsonResponse
    {
        $membership = $request->user()->membershipEn(TenantContext::get());

        try {
            $elector = $capturar->handle($membership, $request->validated());
        } catch (ElectorDuplicado $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'id' => $e->existente->id,
            ], 409);
        }

        return response()->json($this->presentar($elector), 201);
    }

    /**
     * Resolución manual (no binding implícito): SubstituteBindings corre antes
     * que ResolveTenant en el stack web, así que un modelo tenant-scoped bindeado
     * implícitamente se resolvería sin TenantContext. Aquí ya hay tenant activo.
     */
    public function show(string $elector): JsonResponse
    {
        $modelo = Elector::query()->findOrFail($elector);

        return response()->json($this->presentar($modelo));
    }

    public function indexPorSeccion(Seccion $seccion): JsonResponse
    {
        $electores = Elector::query()
            ->where('seccion_id', $seccion->id)
            ->latest()
            ->paginate(50);

        return response()->json($electores);
    }

    /**
     * @return array<string, mixed>
     */
    private function presentar(Elector $elector): array
    {
        return [
            'id' => $elector->id,
            'seccion_id' => $elector->seccion_id,
            'modo_captura' => $elector->modo_captura,
            'nombre' => $elector->nombre,
            'telefono' => $elector->telefono,
            'domicilio' => $elector->domicilio,
            'observaciones' => $elector->observaciones,
            'consentimiento' => $elector->consentimiento,
        ];
    }
}
