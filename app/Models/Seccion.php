<?php

namespace App\Models;

use Database\Factories\SeccionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use MatanYadaev\EloquentSpatial\Objects\MultiPolygon;
use MatanYadaev\EloquentSpatial\Traits\HasSpatial;

class Seccion extends Model
{
    /** @use HasFactory<SeccionFactory> */
    use HasFactory;

    use HasSpatial;

    protected $table = 'secciones';

    protected $fillable = [
        'municipio_id',
        'numero',
        'tipo',
        'distrito_federal',
        'distrito_local',
        'lista_nominal',
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
     * @return BelongsTo<Municipio, $this>
     */
    public function municipio(): BelongsTo
    {
        return $this->belongsTo(Municipio::class);
    }
}
