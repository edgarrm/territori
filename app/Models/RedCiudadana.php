<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Database\Factories\RedCiudadanaFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Red ciudadana: forma de captura en la que un usuario "enlace" agrega y ve los
 * registros de su red. El enlace puede ser una membership de cualquier rol.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $enlace_membership_id
 * @property string $nombre
 * @property string|null $descripcion
 * @property bool $activa
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Membership $enlace
 */
class RedCiudadana extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<RedCiudadanaFactory> */
    use HasFactory;

    protected $table = 'redes_ciudadanas';

    protected $fillable = [
        'tenant_id',
        'enlace_membership_id',
        'nombre',
        'descripcion',
        'activa',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'activa' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Membership, $this>
     */
    public function enlace(): BelongsTo
    {
        return $this->belongsTo(Membership::class, 'enlace_membership_id');
    }

    /**
     * @return HasMany<Elector, $this>
     */
    public function electores(): HasMany
    {
        return $this->hasMany(Elector::class);
    }
}
