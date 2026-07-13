<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Support\Tenancy\TenantContext;
use Database\Factories\CoberturaSeccionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * PK compuesta (tenant_id, seccion_id). Eloquent no soporta PK compuesta:
 * $primaryKey=null hace que save()/update() en una instancia NO lleve WHERE
 * (afectaría TODAS las filas de la tabla). Por eso esta clase prohíbe
 * save()/update() de instancia y obliga a pasar por upsertParaSeccion(),
 * que siempre filtra explícitamente por (tenant_id, seccion_id).
 *
 * @property int $tenant_id
 * @property int $seccion_id
 * @property int $capturados
 * @property int $meta
 * @property float $cobertura
 * @property float $penetracion
 * @property int $verificados
 * @property float $movilizacion_verificada
 * @property Carbon|null $actualizado_en
 */
class CoberturaSeccion extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<CoberturaSeccionFactory> */
    use HasFactory;

    protected $table = 'cobertura_seccion';

    public $incrementing = false;

    public $timestamps = false;

    protected $primaryKey = null;

    protected $fillable = [
        'tenant_id',
        'seccion_id',
        'capturados',
        'meta',
        'cobertura',
        'penetracion',
        'verificados',
        'movilizacion_verificada',
        'actualizado_en',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'cobertura' => 'decimal:4',
            'penetracion' => 'decimal:4',
            'movilizacion_verificada' => 'decimal:4',
            'actualizado_en' => 'datetime',
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
     * Único punto de escritura seguro: upsert explícito por (tenant_id, seccion_id).
     * Nunca usar save()/update() sobre una instancia (ver nota de clase).
     *
     * @param  array<string, mixed>  $atributos
     */
    public static function upsertParaSeccion(int $seccionId, array $atributos): self
    {
        $tenantId = TenantContext::get()?->id;

        $query = static::query()->where('tenant_id', $tenantId)->where('seccion_id', $seccionId);

        if ($query->exists()) {
            $query->update($atributos);
        } else {
            static::create(array_merge(['tenant_id' => $tenantId, 'seccion_id' => $seccionId], $atributos));
        }

        return static::query()->where('tenant_id', $tenantId)->where('seccion_id', $seccionId)->first();
    }

    public function save(array $options = []): bool
    {
        if ($this->exists) {
            throw new \LogicException('CoberturaSeccion no soporta update() de instancia (PK compuesta). Usa CoberturaSeccion::upsertParaSeccion().');
        }

        return parent::save($options);
    }
}
