<?php

namespace App\Actions\Privacidad;

use App\Actions\Cobertura\RecalcularCoberturaSeccion;
use App\Models\Elector;
use App\Models\Seccion;
use App\Models\SolicitudArco;
use Illuminate\Support\Facades\DB;

/**
 * Ejecuta la Cancelación ARCO (ADR-004): baja lógica del elector + scrub de su
 * PII (los datos personales se eliminan; la fila queda soft-deleted para
 * auditoría junto al registro en solicitudes_arco), y recálculo de la cobertura
 * de la sección (el conteo de vivos baja). Atómico. Requiere TenantContext activo.
 */
class CancelarElector
{
    public function __construct(private RecalcularCoberturaSeccion $recalcular) {}

    /**
     * Ejecuta la cancelación. Si se pasa una solicitud existente (la pendiente que
     * un coordinador atiende desde la bandeja) se marca atendida en vez de crear
     * una nueva; de lo contrario se registra una cancelación ya atendida (baja
     * directa desde la ficha del elector).
     */
    public function handle(Elector $elector, ?SolicitudArco $solicitud = null): void
    {
        $seccionId = $elector->seccion_id;

        DB::transaction(function () use ($elector, $seccionId, $solicitud): void {
            // Scrub de PII antes de la baja lógica (deja de existir el dato personal).
            // nombre/telefono son NOT NULL → cadena vacía; el resto a null.
            $elector->forceFill([
                'nombre' => '',
                'telefono' => '',
                'telefono_hash' => null,
                'domicilio' => null,
                'ubicacion' => null,
                'observaciones' => null,
            ])->save();

            $elector->delete();

            if ($solicitud !== null) {
                $solicitud->update(['estado' => 'atendida', 'atendido_en' => now()]);
            } else {
                SolicitudArco::create([
                    'elector_id' => $elector->id,
                    'tipo' => 'cancelacion',
                    'estado' => 'atendida',
                    'solicitado_en' => now(),
                    'atendido_en' => now(),
                ]);
            }

            $this->recalcular->handle(Seccion::findOrFail($seccionId));
        });
    }
}
