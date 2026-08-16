<?php

namespace App\Models;

use App\Enums\CanalEncuesta;
use App\Models\Concerns\BelongsToTenant;
use Database\Factories\EncuestaFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Encuesta canal-agnóstica (F8): preguntas propias + respuestas agregadas por
 * ámbito (sección/zona). El canal 'nativo' (formulario propio) es el primero;
 * canales pull (Sheets/Twilio) llegan en F9+.
 *
 * @property int $id
 * @property int $tenant_id
 * @property string $nombre
 * @property CanalEncuesta $canal
 * @property array<string, mixed>|null $configuracion_canal
 * @property string $ambito_tipo
 * @property string $modo_acceso
 * @property string|null $token_publico
 * @property string $estado
 * @property Carbon|null $ultima_sincronizacion
 * @property array<string, mixed>|null $ultimo_resultado
 * @property string|null $ultimo_error
 * @property int|null $creado_por_membership_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Encuesta extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<EncuestaFactory> */
    use HasFactory;

    protected $table = 'encuestas';

    protected $fillable = [
        'nombre',
        'canal',
        'configuracion_canal',
        'ambito_tipo',
        'modo_acceso',
        'token_publico',
        'estado',
        'ultima_sincronizacion',
        'ultimo_resultado',
        'ultimo_error',
        'creado_por_membership_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'canal' => CanalEncuesta::class,
            'configuracion_canal' => 'array',
            'ultima_sincronizacion' => 'datetime',
            'ultimo_resultado' => 'array',
        ];
    }

    /**
     * @return HasMany<EncuestaPregunta, $this>
     */
    public function preguntas(): HasMany
    {
        return $this->hasMany(EncuestaPregunta::class)->orderBy('orden');
    }

    /**
     * @return BelongsTo<Membership, $this>
     */
    public function creadoPor(): BelongsTo
    {
        return $this->belongsTo(Membership::class, 'creado_por_membership_id');
    }

    public function esPublica(): bool
    {
        return in_array($this->modo_acceso, ['publico', 'ambos'], true);
    }
}
