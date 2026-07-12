<?php

namespace App\Models;

use Database\Factories\TenantFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

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
     * Categorías de competitividad por defecto: reproducen el comportamiento
     * fijo previo a que fueran configurables (Ganada franca/Competida/
     * Empatada/Perdida), evaluadas de mayor a menor `umbral`. La última
     * categoría es un catch-all: su `umbral` es `null` y siempre matchea.
     *
     * @var list<array{nombre: string, color: string, umbral: int|null}>
     */
    private const CATEGORIAS_COMPETITIVIDAD_DEFAULT = [
        ['nombre' => 'Ganada franca', 'color' => '#15803d', 'umbral' => 30],
        ['nombre' => 'Competida', 'color' => '#f59e0b', 'umbral' => 1],
        ['nombre' => 'Empatada', 'color' => '#6b7280', 'umbral' => 0],
        ['nombre' => 'Perdida', 'color' => '#dc2626', 'umbral' => null],
    ];

    /**
     * Configuración por defecto del análisis electoral (F1, jul-2026) cuando
     * el tenant no ha guardado `settings` propios.
     *
     * @var array<string, mixed>
     */
    private const CONFIGURACION_DEFAULT = [
        'umbral_alfa' => 1000,
        'umbral_beta' => 500,
        'modo_calculo_competitividad' => 'votos',
        'categorias_competitividad' => self::CATEGORIAS_COMPETITIVIDAD_DEFAULT,
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
     * las categorías de competitividad default, umbral alfa/beta 1000/500,
     * modo de cálculo "votos" y los 4 indicadores en true).
     *
     * `categorias_competitividad` se trata como reemplazo completo (no merge
     * recursivo por índice) para que un tenant con menos categorías que el
     * default no "resucite" las categorías sobrantes.
     *
     * @return array<string, mixed>
     */
    public function configuracion(): array
    {
        $settings = $this->settings ?? [];

        $configuracion = array_replace_recursive(
            self::CONFIGURACION_DEFAULT,
            Arr::except($settings, ['categorias_competitividad']),
        );

        $configuracion['categorias_competitividad'] = $this->normalizarCategorias($settings);

        return $configuracion;
    }

    /**
     * Resuelve `categorias_competitividad` con `slug` precomputado (para no
     * recalcularlo por sección al evaluar competitividad). Si el tenant no
     * tiene categorías propias pero sí el `umbral_ganada_franca` legado
     * (shape previo a que las categorías fueran configurables), sintetiza la
     * lista default con ese umbral aplicado a la primera categoría.
     *
     * @param  array<string, mixed>  $settings
     * @return list<array{nombre: string, color: string, umbral: int|float|null, slug: string}>
     */
    private function normalizarCategorias(array $settings): array
    {
        $categorias = $settings['categorias_competitividad'] ?? null;

        if ($categorias === null && isset($settings['umbral_ganada_franca'])) {
            $categorias = self::CATEGORIAS_COMPETITIVIDAD_DEFAULT;
            $categorias[0]['umbral'] = $settings['umbral_ganada_franca'];
        }

        $categorias ??= self::CATEGORIAS_COMPETITIVIDAD_DEFAULT;

        return collect($categorias)
            ->map(fn (array $categoria): array => [...$categoria, 'slug' => Str::slug($categoria['nombre'])])
            ->all();
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
