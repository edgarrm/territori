<?php

namespace App\Models;

use Database\Factories\MunicipioFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use MatanYadaev\EloquentSpatial\Objects\MultiPolygon;
use MatanYadaev\EloquentSpatial\Traits\HasSpatial;

class Municipio extends Model
{
    /** @use HasFactory<MunicipioFactory> */
    use HasFactory;

    use HasSpatial;

    protected $fillable = [
        'entidad_id',
        'clave',
        'nombre',
        'geom',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'geom' => MultiPolygon::class,
        ];
    }

    /**
     * @return BelongsTo<Entidad, $this>
     */
    public function entidad(): BelongsTo
    {
        return $this->belongsTo(Entidad::class);
    }

    /**
     * @return HasMany<Seccion, $this>
     */
    public function secciones(): HasMany
    {
        return $this->hasMany(Seccion::class);
    }
}
