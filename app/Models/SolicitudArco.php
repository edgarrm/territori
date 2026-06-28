<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Database\Factories\SolicitudArcoFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Solicitud de derechos ARCO (LFPDPPP, ADR-004): Acceso, Rectificación,
 * Cancelación, Oposición. La Cancelación ejecutada se registra atendida y va
 * acompañada de la baja lógica + scrub del elector.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int|null $elector_id
 * @property string $tipo
 * @property string $estado
 * @property Carbon $solicitado_en
 * @property Carbon|null $atendido_en
 */
class SolicitudArco extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<SolicitudArcoFactory> */
    use HasFactory;

    protected $table = 'solicitudes_arco';

    public $timestamps = false;

    protected $fillable = [
        'tenant_id',
        'elector_id',
        'tipo',
        'estado',
        'solicitado_en',
        'atendido_en',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'solicitado_en' => 'datetime',
            'atendido_en' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Elector, $this>
     */
    public function elector(): BelongsTo
    {
        return $this->belongsTo(Elector::class);
    }
}
