<?php

namespace App\Models\Concerns;

use App\Models\Tenant;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

trait BelongsToTenant
{
    public static function bootBelongsToTenant(): void
    {
        static::addGlobalScope(new TenantScope);

        static::creating(function ($model) {
            // Incondicional a propósito: tenant_id nunca es fillable en estos
            // modelos, así que un valor explícito aquí solo puede venir de código
            // interno (factories/seeders) que debe fijar el TenantContext, no del
            // propio atributo. Evita el salto de tenant si algún día se vuelve
            // fillable por error (A5).
            $model->tenant_id = TenantContext::get()?->id;
        });
    }

    /**
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
