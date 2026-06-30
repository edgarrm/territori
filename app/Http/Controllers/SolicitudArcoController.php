<?php

namespace App\Http\Controllers;

use App\Actions\Privacidad\CancelarElector;
use App\Actions\Privacidad\RegistrarSolicitudArco;
use App\Http\Requests\StoreSolicitudArcoRequest;
use App\Models\Elector;
use App\Models\SolicitudArco;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SolicitudArcoController extends Controller
{
    public function store(StoreSolicitudArcoRequest $request, RegistrarSolicitudArco $registrar): JsonResponse
    {
        $solicitud = $registrar->handle($request->validated());

        return response()->json([
            'id' => $solicitud->id,
            'tipo' => $solicitud->tipo,
            'estado' => $solicitud->estado,
            'elector_id' => $solicitud->elector_id,
        ], 201);
    }

    /**
     * Bandeja de solicitudes ARCO del tenant (gestión). Filtra por estado:
     * pendientes por defecto, con opción de ver el historial de atendidas.
     */
    public function index(Request $request): Response
    {
        $estado = $request->query('estado') === 'atendida' ? 'atendida' : 'pendiente';

        $solicitudes = SolicitudArco::query()
            ->where('estado', $estado)
            ->with('elector:id,nombre')
            ->orderByDesc('solicitado_en')
            ->get()
            ->map(fn (SolicitudArco $solicitud) => [
                'id' => $solicitud->id,
                'tipo' => $solicitud->tipo,
                'estado' => $solicitud->estado,
                'elector_id' => $solicitud->elector_id,
                'elector' => $solicitud->elector ? ['id' => $solicitud->elector->id, 'nombre' => $solicitud->elector->nombre] : null,
                'solicitado_en' => $solicitud->solicitado_en?->toIso8601String(),
                'atendido_en' => $solicitud->atendido_en?->toIso8601String(),
            ]);

        return Inertia::render('SolicitudesArco', [
            'estado' => $estado,
            'solicitudes' => $solicitudes,
        ]);
    }

    /**
     * Atiende una solicitud. La Cancelación ejecuta la baja lógica + scrub del
     * elector (reusa CancelarElector, marcando esta misma solicitud atendida sin
     * duplicar). Los demás derechos solo se acusan como atendidos: la resolución
     * (entregar datos, rectificar, dejar de contactar) se hace en la ficha.
     */
    public function atendido(string $solicitud, CancelarElector $cancelar): JsonResponse
    {
        // Resolución manual tenant-scoped: SubstituteBindings corre antes que
        // ResolveTenant, así que el route-model binding no ve el TenantContext.
        $solicitud = SolicitudArco::query()->findOrFail($solicitud);

        if ($solicitud->estado === 'atendida') {
            return response()->json(['estado' => 'atendida']);
        }

        $elector = $solicitud->elector_id !== null ? Elector::query()->find($solicitud->elector_id) : null;

        if ($solicitud->tipo === 'cancelacion' && $elector !== null) {
            $cancelar->handle($elector, $solicitud);
        } else {
            $solicitud->update(['estado' => 'atendida', 'atendido_en' => now()]);
        }

        return response()->json(['estado' => 'atendida']);
    }
}
