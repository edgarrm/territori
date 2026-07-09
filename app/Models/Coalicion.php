<?php

namespace App\Models;

use Database\Factories\CoalicionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Catálogo global de coaliciones por año electoral (sin tenant_id). Las
 * siglas de `partidos` son el identificador natural en `votos_partidos`, así
 * que se guardan como array plano y no como pivot.
 *
 * @property int $id
 * @property string $nombre
 * @property int $anio
 * @property array<int, string> $partidos
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Coalicion extends Model
{
    /** @use HasFactory<CoalicionFactory> */
    use HasFactory;

    protected $table = 'coaliciones';

    protected $fillable = [
        'nombre',
        'anio',
        'partidos',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'partidos' => 'array',
        ];
    }
}
