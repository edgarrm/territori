<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Database\Factories\LoteriaFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Lotería: registro de captura masiva con nombre y fecha, ligado a una sección
 * y a cargo de un encargado (membership). Los electores capturados la referencian.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $membership_id
 * @property int $seccion_id
 * @property string $nombre
 * @property Carbon $fecha
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
        'nombre',
        'fecha',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'fecha' => 'datetime',
        ];
    }

    /**
     * Encargado de la lotería.
     *
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

    /**
     * @return HasMany<Elector, $this>
     */
    public function electores(): HasMany
    {
        return $this->hasMany(Elector::class);
    }
}
