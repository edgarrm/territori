<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Conteo agregado de una opción de respuesta, por pregunta y ámbito
 * (sección/zona). Nunca guarda la respuesta cruda individual: solo el
 * incremental. Única vía de escritura: App\Actions\Encuestas\TabularRespuesta.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $encuesta_id
 * @property int $pregunta_id
 * @property string $ambito_tipo
 * @property int $ambito_id
 * @property string $opcion
 * @property int $conteo
 * @property Carbon|null $actualizado_en
 */
class EncuestaRespuestaAgregada extends Model
{
    use BelongsToTenant;

    protected $table = 'encuesta_respuestas_agregadas';

    public $timestamps = false;

    protected $fillable = [
        'encuesta_id',
        'pregunta_id',
        'ambito_tipo',
        'ambito_id',
        'opcion',
        'conteo',
        'actualizado_en',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'conteo' => 'integer',
            'actualizado_en' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Encuesta, $this>
     */
    public function encuesta(): BelongsTo
    {
        return $this->belongsTo(Encuesta::class);
    }

    /**
     * @return BelongsTo<EncuestaPregunta, $this>
     */
    public function pregunta(): BelongsTo
    {
        return $this->belongsTo(EncuestaPregunta::class, 'pregunta_id');
    }
}
