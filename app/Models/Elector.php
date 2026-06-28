<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Database\Factories\ElectorFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use MatanYadaev\EloquentSpatial\Objects\Point;
use MatanYadaev\EloquentSpatial\Traits\HasSpatial;

/**
 * Entidad central de Territori: un contacto georreferenciado capturado por un
 * brigadista (membership) en una sección. telefono/domicilio cifrados en reposo
 * (ADR-004); telefono_hash determinista separado para dedup.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $seccion_id
 * @property int $membership_id
 * @property string $modo_captura
 * @property int|null $loteria_id
 * @property int|null $evento_id
 * @property string $nombre
 * @property string $telefono
 * @property string|null $telefono_hash
 * @property string|null $domicilio
 * @property Point|null $ubicacion
 * @property string|null $observaciones
 * @property bool $consentimiento
 * @property int $aviso_privacidad_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 */
class Elector extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<ElectorFactory> */
    use HasFactory;

    use HasSpatial;
    use SoftDeletes;

    protected $table = 'electores';

    protected $fillable = [
        'tenant_id',
        'seccion_id',
        'membership_id',
        'modo_captura',
        'loteria_id',
        'evento_id',
        'nombre',
        'telefono',
        'telefono_hash',
        'domicilio',
        'ubicacion',
        'observaciones',
        'consentimiento',
        'aviso_privacidad_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'telefono' => 'encrypted',
            'domicilio' => 'encrypted',
            'consentimiento' => 'boolean',
            'ubicacion' => Point::class,
        ];
    }

    /**
     * @return BelongsTo<Seccion, $this>
     */
    public function seccion(): BelongsTo
    {
        return $this->belongsTo(Seccion::class);
    }

    /**
     * @return BelongsTo<Membership, $this>
     */
    public function membership(): BelongsTo
    {
        return $this->belongsTo(Membership::class);
    }

    /**
     * @return BelongsTo<Loteria, $this>
     */
    public function loteria(): BelongsTo
    {
        return $this->belongsTo(Loteria::class);
    }

    /**
     * @return BelongsTo<Evento, $this>
     */
    public function evento(): BelongsTo
    {
        return $this->belongsTo(Evento::class);
    }

    /**
     * @return BelongsTo<AvisoPrivacidad, $this>
     */
    public function avisoPrivacidad(): BelongsTo
    {
        return $this->belongsTo(AvisoPrivacidad::class, 'aviso_privacidad_id');
    }

    /**
     * Timeline de interacciones, más reciente primero.
     *
     * @return HasMany<Interaccion, $this>
     */
    public function interacciones(): HasMany
    {
        return $this->hasMany(Interaccion::class)->orderByDesc('fecha');
    }
}
