<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Database\Factories\MetaSeccionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $tenant_id
 * @property int $seccion_id
 * @property int $meta_capturas
 * @property string $fuente_meta
 * @property float|null $pct_lista_nominal
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class MetaSeccion extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<MetaSeccionFactory> */
    use HasFactory;

    protected $table = 'metas_seccion';

    protected $fillable = [
        'tenant_id',
        'seccion_id',
        'meta_capturas',
        'fuente_meta',
        'pct_lista_nominal',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'pct_lista_nominal' => 'decimal:2',
        ];
    }

    /**
     * @return BelongsTo<Seccion, $this>
     */
    public function seccion(): BelongsTo
    {
        return $this->belongsTo(Seccion::class);
    }
}
