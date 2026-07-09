<?php

namespace App\Models;

use Database\Factories\PartidoFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Catálogo global de partidos políticos (sin tenant_id). Sembrado por
 * PartidoSeeder; el cliente/CRUD queda fuera de alcance de esta iteración.
 *
 * @property int $id
 * @property string $siglas
 * @property string $nombre
 * @property string $color
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Partido extends Model
{
    /** @use HasFactory<PartidoFactory> */
    use HasFactory;

    protected $fillable = [
        'siglas',
        'nombre',
        'color',
    ];
}
