<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Database\Factories\ConexionGoogleFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Conexión OAuth de la cuenta de Google del tenant (F9): una por tenant,
 * alimenta el canal de encuestas Google Sheets. access_token/refresh_token
 * cifrados en reposo (mismo mecanismo que electores.telefono, ADR-004),
 * aunque es un secreto operativo de la campaña, no PII de un ciudadano.
 *
 * @property int $id
 * @property int $tenant_id
 * @property string $access_token
 * @property string $refresh_token
 * @property Carbon $expira_en
 * @property string $cuenta_email
 * @property int|null $conectado_por_membership_id
 * @property Carbon $conectado_en
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class ConexionGoogle extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<ConexionGoogleFactory> */
    use HasFactory;

    protected $table = 'conexiones_google';

    protected $fillable = [
        'access_token',
        'refresh_token',
        'expira_en',
        'cuenta_email',
        'conectado_por_membership_id',
        'conectado_en',
    ];

    /**
     * Defensa en profundidad: una serialización accidental (toArray/toJson)
     * no debe filtrar los tokens, aunque hoy nada expone este modelo así.
     *
     * @var list<string>
     */
    protected $hidden = [
        'access_token',
        'refresh_token',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'access_token' => 'encrypted',
            'refresh_token' => 'encrypted',
            'expira_en' => 'datetime',
            'conectado_en' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Membership, $this>
     */
    public function conectadoPor(): BelongsTo
    {
        return $this->belongsTo(Membership::class, 'conectado_por_membership_id');
    }
}
