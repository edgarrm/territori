<?php

namespace App\Actions\Privacidad;

use App\Models\Elector;
use App\Models\Interaccion;
use App\Models\SolicitudArco;
use App\Support\Tenancy\TenantContext;

/**
 * Derecho de Acceso (LFPDPPP art. 22 y 33): arma el reporte de **todo** lo que
 * la campaña guarda sobre una persona, para entregárselo al titular.
 *
 * Hasta P4 marcar la solicitud "atendida" era un acuse vacío: no existía nada
 * que entregar. El reporte va sin enmascarar a propósito —el destinatario es el
 * propio titular—, por eso la ruta exige gestión + 2FA y cada generación deja
 * traza en la bitácora de accesos.
 */
class ReporteAcceso
{
    public function __construct(private RegistrarAcceso $bitacora) {}

    /**
     * @return array<string, mixed>
     */
    public function handle(Elector $elector): array
    {
        $elector->loadMissing(['seccion:id,numero', 'avisoPrivacidad', 'membership.user', 'interacciones']);

        // La ruta vive bajo el middleware `tenant`, así que aquí siempre hay
        // campaña activa y la traza se escribe. Es el acceso a PII más completo
        // de la app sobre una sola persona: no puede quedar sin registrar.
        $this->bitacora->handle('elector.reporte_acceso', 'elector', $elector->id);

        return [
            'generado_en' => now()->toIso8601String(),
            'responsable' => TenantContext::get()?->nombre,
            'datos_personales' => [
                'nombre' => $elector->nombre,
                'telefono' => $elector->telefono,
                'email' => $elector->email,
                'domicilio' => $elector->domicilio,
                'ubicacion' => $elector->ubicacion,
                'observaciones' => $elector->observaciones,
            ],
            'origen' => [
                'modo_captura' => $elector->modo_captura,
                'seccion' => $elector->seccion?->numero,
                'capturado_en' => $elector->created_at?->toIso8601String(),
                'capturado_por' => $elector->membership?->user?->name,
            ],
            'consentimiento' => [
                'otorgado' => $elector->consentimiento,
                'en' => $elector->consentimiento_en?->toIso8601String(),
                'via' => $elector->consentimiento_via,
                'aviso_version' => $elector->avisoPrivacidad?->version,
            ],
            'verificacion' => [
                'verificado' => $elector->verificado_en !== null,
                'via' => $elector->verificado_via,
                'en' => $elector->verificado_en?->toIso8601String(),
            ],
            'preferencias' => [
                'no_contactar' => $elector->no_contactar,
            ],
            'interacciones' => $elector->interacciones
                ->map(fn (Interaccion $i): array => [
                    'tipo' => $i->tipo,
                    'resultado' => $i->resultado,
                    'nota' => $i->nota,
                    'fecha' => $i->fecha->toIso8601String(),
                ])->all(),
            'solicitudes_arco' => SolicitudArco::query()
                ->where('elector_id', $elector->id)
                ->orderBy('solicitado_en')
                ->get()
                ->map(fn (SolicitudArco $s): array => [
                    'tipo' => $s->tipo,
                    'estado' => $s->estado,
                    'solicitado_en' => $s->solicitado_en->toIso8601String(),
                    'atendido_en' => $s->atendido_en?->toIso8601String(),
                ])->all(),
        ];
    }
}
