<?php

namespace App\Models;

use Database\Factories\EntidadFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use MatanYadaev\EloquentSpatial\Objects\MultiPolygon;
use MatanYadaev\EloquentSpatial\Traits\HasSpatial;

class Entidad extends Model
{
    /** @use HasFactory<EntidadFactory> */
    use HasFactory;

    use HasSpatial;

    protected $fillable = [
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
     * @return HasMany<Municipio, $this>
     */
    public function municipios(): HasMany
    {
        return $this->hasMany(Municipio::class);
    }
}
