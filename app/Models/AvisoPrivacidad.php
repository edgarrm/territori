<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Database\Factories\AvisoPrivacidadFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $tenant_id
 * @property string $version
 * @property string $texto
 * @property Carbon $vigente_desde
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class AvisoPrivacidad extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<AvisoPrivacidadFactory> */
    use HasFactory;

    protected $table = 'avisos_privacidad';

    protected $fillable = [
        'tenant_id',
        'version',
        'texto',
        'vigente_desde',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'vigente_desde' => 'datetime',
        ];
    }

    /**
     * Aviso vigente del tenant activo: el más reciente por vigente_desde.
     */
    public static function vigente(): ?self
    {
        return static::query()
            ->where('vigente_desde', '<=', now())
            ->orderByDesc('vigente_desde')
            ->first();
    }
}
