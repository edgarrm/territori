<?php

namespace App\Models;

use Database\Factories\MembershipFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $tenant_id
 * @property int $user_id
 * @property string $rol
 * @property int|null $meta_diaria
 * @property bool $activo
 * @property Carbon|null $activado_en
 * @property Carbon|null $desactivado_en
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Tenant $tenant
 * @property-read User $user
 */
class Membership extends Model
{
    /** @use HasFactory<MembershipFactory> */
    use HasFactory;

    protected $fillable = [
        'tenant_id',
        'user_id',
        'rol',
        'meta_diaria',
        'activo',
        'activado_en',
        'desactivado_en',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'activo' => 'boolean',
            'activado_en' => 'datetime',
            'desactivado_en' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Zonas asignadas al brigadista. El pivote lleva tenant_id (memberships no
     * usa global scope), así que las escrituras deben setearlo explícito.
     *
     * @return BelongsToMany<Seccion, $this>
     */
    public function secciones(): BelongsToMany
    {
        return $this->belongsToMany(Seccion::class, 'brigadista_seccion', 'membership_id', 'seccion_id')
            ->withPivot('tenant_id');
    }

    public function esBrigadista(): bool
    {
        return $this->rol === 'brigadista';
    }

    public function esEnlace(): bool
    {
        return $this->rol === 'enlace';
    }

    public function esAnfitrion(): bool
    {
        return $this->rol === 'anfitrion';
    }

    public function esGestion(): bool
    {
        return in_array($this->rol, ['coordinador', 'admin'], true);
    }

    /**
     * ¿Puede capturar en esta sección? Brigadista y anfitrión están acotados a
     * sus zonas asignadas (brigadista_seccion); sin zonas no pueden capturar en
     * ninguna. Gestión (coordinador/admin) y otros roles no dependen de zonas.
     */
    public function puedeCapturarEnSeccion(int $seccionId): bool
    {
        if (! $this->esBrigadista() && ! $this->esAnfitrion()) {
            return true;
        }

        return $this->secciones()->whereKey($seccionId)->exists();
    }

    /**
     * Redes ciudadanas de las que esta membership es el enlace responsable.
     *
     * @return HasMany<RedCiudadana, $this>
     */
    public function redesComoEnlace(): HasMany
    {
        return $this->hasMany(RedCiudadana::class, 'enlace_membership_id');
    }

    /**
     * Roles que esta membership puede asignar al invitar a otro miembro. El
     * admin da de alta cualquier rol; el coordinador solo brigadistas, enlaces
     * y anfitriones (nunca coordinadores ni admins, para evitar escalación de
     * privilegios).
     *
     * @return list<string>
     */
    public function rolesQuePuedeAsignar(): array
    {
        return match ($this->rol) {
            'admin' => ['admin', 'coordinador', 'brigadista', 'enlace', 'anfitrion'],
            'coordinador' => ['brigadista', 'enlace', 'anfitrion'],
            default => [],
        };
    }

    public function puedeAsignarRol(string $rol): bool
    {
        return in_array($rol, $this->rolesQuePuedeAsignar(), true);
    }

    /**
     * Ruta de aterrizaje según el rol: los roles de acceso restringido entran
     * directo a su área (enlace → redes, anfitrión → loterías); el resto al
     * dashboard.
     */
    public function rutaInicial(): string
    {
        return match ($this->rol) {
            'enlace' => 'redes-ciudadanas.index',
            'anfitrion' => 'loterias.index',
            default => 'dashboard',
        };
    }

    public function activar(): void
    {
        if ($this->rol === 'brigadista' && ! $this->tenant->puedeActivarBrigadista()) {
            throw new \RuntimeException('Se alcanzó el límite de brigadistas activos para esta campaña. Actualiza tu plan para activar a más.');
        }

        $this->update(['activo' => true, 'activado_en' => now()]);
    }

    public function desactivar(): void
    {
        $this->update(['activo' => false, 'desactivado_en' => now()]);
    }
}
