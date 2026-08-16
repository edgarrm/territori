<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Database\Factories\ZonaFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use MatanYadaev\EloquentSpatial\Objects\MultiPolygon;
use MatanYadaev\EloquentSpatial\Traits\HasSpatial;

/**
 * Zona territorial personalizada del tenant (F6): cualquier nivel
 * (ciudad/municipio/estado/país/continente/mundial/custom), independiente de
 * la jerarquía electoral INE y, a diferencia de `Seccion`, NO restringida al
 * municipio del tenant.
 *
 * @property int $id
 * @property int $tenant_id
 * @property string $nombre
 * @property string|null $clave
 * @property string|null $descripcion
 * @property string $tipo
 * @property int|null $zona_padre_id
 * @property MultiPolygon|null $geom
 * @property string $fuente
 * @property bool $activa
 * @property int|null $creado_por_membership_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Zona|null $padre
 */
class Zona extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<ZonaFactory> */
    use HasFactory;

    use HasSpatial;

    protected $table = 'zonas';

    protected $fillable = [
        'nombre',
        'clave',
        'descripcion',
        'tipo',
        'zona_padre_id',
        'geom',
        'fuente',
        'activa',
        'creado_por_membership_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'geom' => MultiPolygon::class,
            'activa' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Zona, $this>
     */
    public function padre(): BelongsTo
    {
        return $this->belongsTo(Zona::class, 'zona_padre_id');
    }

    /**
     * @return HasMany<Zona, $this>
     */
    public function hijas(): HasMany
    {
        return $this->hasMany(Zona::class, 'zona_padre_id');
    }

    /**
     * @return BelongsTo<Membership, $this>
     */
    public function creadoPor(): BelongsTo
    {
        return $this->belongsTo(Membership::class, 'creado_por_membership_id');
    }
}
