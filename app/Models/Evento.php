<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Database\Factories\EventoFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use MatanYadaev\EloquentSpatial\Objects\Point;
use MatanYadaev\EloquentSpatial\Traits\HasSpatial;

/**
 * Evento de campaña (mitin/reunión): contenedor de captura en bloque
 * (modo_captura='evento'). seccion_id es la sede; los electores capturados
 * heredan esa sección.
 *
 * @property int $id
 * @property int $tenant_id
 * @property string $nombre
 * @property string $tipo
 * @property Carbon $fecha
 * @property string|null $lugar
 * @property int|null $seccion_id
 * @property Point|null $ubicacion
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Evento extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<EventoFactory> */
    use HasFactory;

    use HasSpatial;

    protected $table = 'eventos';

    protected $fillable = [
        'tenant_id',
        'nombre',
        'tipo',
        'fecha',
        'lugar',
        'seccion_id',
        'ubicacion',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'fecha' => 'datetime',
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
     * @return HasMany<Elector, $this>
     */
    public function electores(): HasMany
    {
        return $this->hasMany(Elector::class);
    }
}
