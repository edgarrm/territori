<?php

namespace App\Support\Tenancy;

use App\Models\Tenant;

class TenantContext
{
    public static function set(Tenant $tenant): void
    {
        app()->instance('currentTenant', $tenant);
    }

    public static function get(): ?Tenant
    {
        return app()->bound('currentTenant') ? app('currentTenant') : null;
    }

    public static function clear(): void
    {
        if (app()->bound('currentTenant')) {
            app()->forgetInstance('currentTenant');
        }
    }
}
