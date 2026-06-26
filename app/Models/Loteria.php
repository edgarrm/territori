<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Database\Factories\LoteriaFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Sesión de captura masiva ("lotería"): sección fija, abierta por un brigadista.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $membership_id
 * @property int $seccion_id
 * @property Carbon $abierta_en
 * @property Carbon|null $cerrada_en
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Loteria extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<LoteriaFactory> */
    use HasFactory;

    protected $table = 'loterias';

    protected $fillable = [
        'tenant_id',
        'membership_id',
        'seccion_id',
        'abierta_en',
        'cerrada_en',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'abierta_en' => 'datetime',
            'cerrada_en' => 'datetime',
        ];
    }

    /**
     * @param  Builder<Loteria>  $query
     */
    public function scopeAbiertas(Builder $query): void
    {
        $query->whereNull('cerrada_en');
    }

    public function cerrar(): void
    {
        $this->update(['cerrada_en' => now()]);
    }

    /**
     * @return BelongsTo<Membership, $this>
     */
    public function membership(): BelongsTo
    {
        return $this->belongsTo(Membership::class);
    }

    /**
     * @return BelongsTo<Seccion, $this>
     */
    public function seccion(): BelongsTo
    {
        return $this->belongsTo(Seccion::class);
    }
}
