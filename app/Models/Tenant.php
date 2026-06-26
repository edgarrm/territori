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
 * @property string $plan
 * @property string $estado
 * @property int|null $limite_brigadistas
 * @property string|null $marca_nombre
 * @property string|null $marca_logo_url
 * @property string|null $marca_color
 * @property string|null $subdominio
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Tenant extends Model
{
    /** @use HasFactory<TenantFactory> */
    use HasFactory;

    protected $fillable = [
        'nombre',
        'municipio_id',
        'plan',
        'estado',
        'limite_brigadistas',
        'marca_nombre',
        'marca_logo_url',
        'marca_color',
        'subdominio',
    ];

    /**
     * @return BelongsTo<Municipio, $this>
     */
    public function municipio(): BelongsTo
    {
        return $this->belongsTo(Municipio::class);
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
