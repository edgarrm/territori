<?php

namespace App\Http\Middleware;

use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ResolveTenant
{
    /**
     * Resuelve el tenant activo: por subdominio si matchea, si no por el
     * guardado en sesión. Corre después de auth. NUNCA confía en el request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $tenant = $this->tenantPorSubdominio($request) ?? $this->tenantPorSesion($request);

        $user = $request->user();

        if ($tenant && $user instanceof User) {
            $membership = $user->membershipEn($tenant);

            if (! $membership || ! $membership->activo) {
                $tenant = null;
            }
        }

        if ($tenant) {
            TenantContext::set($tenant);
            $request->session()->put('tenant_id', $tenant->id);
        }

        return $next($request);
    }

    private function tenantPorSubdominio(Request $request): ?Tenant
    {
        $host = $request->getHost();
        $base = config('app.tenant_domain');

        if (! $base || ! str_ends_with($host, '.'.$base)) {
            return null;
        }

        $subdominio = substr($host, 0, -strlen('.'.$base));

        return Tenant::where('subdominio', $subdominio)->first();
    }

    private function tenantPorSesion(Request $request): ?Tenant
    {
        $tenantId = $request->session()->get('tenant_id');

        return $tenantId ? Tenant::query()->whereKey($tenantId)->first() : null;
    }
}
