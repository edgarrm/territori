<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Database\Factories\InteraccionFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Una entrada del timeline de un elector: tipo = canal de contacto,
 * resultado = qué pasó (separados). proximo_seguimiento agenda un pendiente;
 * atendido_en lo cierra y lo saca de la agenda.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $elector_id
 * @property int $membership_id
 * @property string $tipo
 * @property string|null $resultado
 * @property string|null $nota
 * @property Carbon $fecha
 * @property Carbon|null $proximo_seguimiento
 * @property Carbon|null $atendido_en
 * @property Carbon|null $created_at
 */
class Interaccion extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<InteraccionFactory> */
    use HasFactory;

    protected $table = 'interacciones';

    /**
     * La tabla solo tiene created_at (data-model): sin updated_at.
     */
    const UPDATED_AT = null;

    protected $fillable = [
        'tenant_id',
        'elector_id',
        'membership_id',
        'tipo',
        'resultado',
        'nota',
        'fecha',
        'proximo_seguimiento',
        'atendido_en',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'fecha' => 'datetime',
            'proximo_seguimiento' => 'date',
            'atendido_en' => 'datetime',
        ];
    }

    /**
     * Seguimientos vencidos no atendidos (la agenda del día).
     *
     * @param  Builder<Interaccion>  $query
     */
    public function scopePendientes(Builder $query): void
    {
        $query->whereNotNull('proximo_seguimiento')
            ->whereDate('proximo_seguimiento', '<=', now())
            ->whereNull('atendido_en');
    }

    /**
     * @return BelongsTo<Elector, $this>
     */
    public function elector(): BelongsTo
    {
        return $this->belongsTo(Elector::class);
    }

    /**
     * @return BelongsTo<Membership, $this>
     */
    public function membership(): BelongsTo
    {
        return $this->belongsTo(Membership::class);
    }
}
