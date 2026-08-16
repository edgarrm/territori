<?php

namespace App\Http\Controllers;

use App\Actions\Privacidad\CancelarElector;
use App\Actions\Privacidad\RegistrarAcceso;
use App\Actions\Privacidad\RegistrarSolicitudArco;
use App\Actions\Privacidad\ReporteAcceso;
use App\Http\Requests\StoreSolicitudArcoRequest;
use App\Models\Elector;
use App\Models\SolicitudArco;
use App\Support\Tenancy\TenantContext;
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
                'solicitado_en' => $solicitud->solicitado_en->toIso8601String(),
                'atendido_en' => $solicitud->atendido_en?->toIso8601String(),
                // Plazo legal de respuesta: 20 días hábiles (LFPDPPP art. 32).
                'vence_el' => $solicitud->venceEl()->toDateString(),
                'dias_restantes' => $solicitud->diasHabilesRestantes(),
                'vencida' => $solicitud->estaVencida(),
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
    public function atendido(Request $request, string $solicitud, CancelarElector $cancelar, RegistrarAcceso $bitacora): JsonResponse
    {
        // Resolución manual tenant-scoped: SubstituteBindings corre antes que
        // ResolveTenant, así que el route-model binding no ve el TenantContext.
        $solicitud = SolicitudArco::query()->findOrFail($solicitud);

        if ($solicitud->estado === 'atendida') {
            return response()->json(['estado' => 'atendida']);
        }

        $elector = $solicitud->elector_id !== null ? Elector::query()->find($solicitud->elector_id) : null;

        // Quién atendió: sin esto la bandeja no acredita ante el INAI que la
        // solicitud se resolvió, ni por quién (P10).
        $atendioMembershipId = $request->user()?->membershipEn(TenantContext::get())?->id;

        if ($solicitud->tipo === 'cancelacion' && $elector !== null) {
            $solicitud->forceFill(['membership_id' => $atendioMembershipId])->save();
            // CancelarElector ya registra su propia entrada 'elector.cancelado'.
            $cancelar->handle($elector, $solicitud);
        } else {
            // Oposición: el efecto técnico del derecho, no solo el acuse (P9).
            if ($solicitud->tipo === 'oposicion' && $elector !== null) {
                $elector->update(['no_contactar' => true]);
            }

            $solicitud->update([
                'estado' => 'atendida',
                'atendido_en' => now(),
                'membership_id' => $atendioMembershipId,
            ]);
        }

        $bitacora->handle('arco.atendida', 'solicitud_arco', $solicitud->id, [
            'tipo' => $solicitud->tipo,
            'elector_id' => $solicitud->elector_id,
        ]);

        return response()->json(['estado' => 'atendida']);
    }

    /**
     * Derecho de Acceso: entrega al titular todo lo que la campaña guarda sobre
     * él. Va sin enmascarar (el destinatario es el propio titular), por eso la
     * ruta exige gestión + 2FA y la acción deja traza en la bitácora.
     */
    public function reporteAcceso(string $elector, ReporteAcceso $reporte): JsonResponse
    {
        $modelo = Elector::query()->findOrFail($elector);

        return response()->json($reporte->handle($modelo));
    }
}
