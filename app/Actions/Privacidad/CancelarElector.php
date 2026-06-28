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

    public function handle(Elector $elector): void
    {
        $seccionId = $elector->seccion_id;

        DB::transaction(function () use ($elector, $seccionId): void {
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

            SolicitudArco::create([
                'elector_id' => $elector->id,
                'tipo' => 'cancelacion',
                'estado' => 'atendida',
                'solicitado_en' => now(),
                'atendido_en' => now(),
            ]);

            $this->recalcular->handle(Seccion::findOrFail($seccionId));
        });
    }
}
