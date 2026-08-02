<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * Entrada de la bitácora de accesos a datos personales (ADR-004). Se escribe
 * siempre a través de App\Actions\Privacidad\RegistrarAcceso.
 *
 * Append-only: `save()` sobre una instancia existente y `delete()` lanzan
 * LogicException, para que una traza no pueda alterarse ni borrarse desde la
 * aplicación (mismo patrón defensivo que CoberturaSeccion). `metadatos` nunca
 * lleva el valor de un dato personal, solo contexto: filtros, conteos y nombres
 * de campos modificados.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int|null $membership_id
 * @property int|null $user_id
 * @property string $accion
 * @property string|null $recurso_tipo
 * @property int|null $recurso_id
 * @property array<string, mixed>|null $metadatos
 * @property string|null $ip
 * @property string|null $user_agent
 * @property Carbon|null $created_at
 */
class BitacoraAcceso extends Model
{
    use BelongsToTenant;

    protected $table = 'bitacora_accesos';

    /** La tabla no tiene updated_at: una entrada nunca se modifica. */
    public const UPDATED_AT = null;

    protected $fillable = [
        'membership_id',
        'user_id',
        'accion',
        'recurso_tipo',
        'recurso_id',
        'metadatos',
        'ip',
        'user_agent',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'metadatos' => 'array',
            'created_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Membership, $this>
     */
    public function membership(): BelongsTo
    {
        return $this->belongsTo(Membership::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @param  array<string, mixed>  $options
     */
    public function save(array $options = []): bool
    {
        if ($this->exists) {
            throw new LogicException('La bitácora de accesos es append-only: una entrada no puede modificarse.');
        }

        return parent::save($options);
    }

    public function delete(): bool
    {
        throw new LogicException('La bitácora de accesos es append-only: una entrada no puede borrarse.');
    }
}
