<?php

namespace App\Models;

use Database\Factories\TenantFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $nombre
 * @property int $municipio_id
 * @property int|null $partido_id
 * @property string $plan
 * @property string $estado
 * @property int|null $limite_brigadistas
 * @property string|null $marca_nombre
 * @property string|null $marca_logo_url
 * @property string|null $marca_color
 * @property string|null $subdominio
 * @property array<string, mixed>|null $settings
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Tenant extends Model
{
    /** @use HasFactory<TenantFactory> */
    use HasFactory;

    /**
     * Configuración por defecto del análisis electoral (F1, jul-2026) cuando
     * el tenant no ha guardado `settings` propios.
     *
     * @var array<string, mixed>
     */
    private const CONFIGURACION_DEFAULT = [
        'umbral_ganada_franca' => 30,
        'umbral_alfa' => 1000,
        'umbral_beta' => 500,
        'indicadores' => [
            'competitividad' => true,
            'tipo_seccion' => true,
            'indice_neutral' => true,
            'oportunidad' => true,
        ],
    ];

    protected $fillable = [
        'nombre',
        'municipio_id',
        'partido_id',
        'plan',
        'estado',
        'limite_brigadistas',
        'marca_nombre',
        'marca_logo_url',
        'marca_color',
        'subdominio',
        'settings',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'settings' => 'array',
        ];
    }

    /**
     * @return BelongsTo<Municipio, $this>
     */
    public function municipio(): BelongsTo
    {
        return $this->belongsTo(Municipio::class);
    }

    /**
     * @return BelongsTo<Partido, $this>
     */
    public function partido(): BelongsTo
    {
        return $this->belongsTo(Partido::class);
    }

    /**
     * Configuración efectiva del análisis electoral: los `settings`
     * guardados sobre los defaults (un tenant sin settings se comporta con
     * umbral 30/1000/500 y los 4 indicadores en true).
     *
     * @return array<string, mixed>
     */
    public function configuracion(): array
    {
        return array_replace_recursive(self::CONFIGURACION_DEFAULT, $this->settings ?? []);
    }

    /**
     * @return HasMany<Membership, $this>
     */
    public function memberships(): HasMany
    {
        return $this->hasMany(Membership::class);
    }

    public function brigadistasActivosCount(): int
    {
        return $this->memberships()->where('rol', 'brigadista')->where('activo', true)->count();
    }

    public function puedeActivarBrigadista(): bool
    {
        if ($this->limite_brigadistas === null) {
            return true;
        }

        return $this->brigadistasActivosCount() < $this->limite_brigadistas;
    }
}
