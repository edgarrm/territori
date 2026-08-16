<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Database\Factories\EncuestaPreguntaFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Pregunta de una encuesta (F8). `campo` es la columna de origen para canales
 * pull (Sheets, F9+); en 'nativo' siempre es null porque la etiqueta la
 * escribe el coordinador directamente.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $encuesta_id
 * @property string|null $campo
 * @property string $etiqueta
 * @property string $tipo
 * @property array<int, string>|null $opciones
 * @property bool $incluida
 * @property int $orden
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class EncuestaPregunta extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<EncuestaPreguntaFactory> */
    use HasFactory;

    protected $table = 'encuesta_preguntas';

    protected $fillable = [
        'encuesta_id',
        'campo',
        'etiqueta',
        'tipo',
        'opciones',
        'incluida',
        'orden',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'opciones' => 'array',
            'incluida' => 'boolean',
            'orden' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Encuesta, $this>
     */
    public function encuesta(): BelongsTo
    {
        return $this->belongsTo(Encuesta::class);
    }

    public function esTabulable(): bool
    {
        return $this->incluida && $this->tipo === 'opcion_unica';
    }
}
