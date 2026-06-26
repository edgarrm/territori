<?php

namespace App\Http\Middleware;

use App\Support\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureRol
{
    /**
     * Autoriza por el rol en la membership activa del tenant actual.
     * Nunca lee el rol de $user directamente.
     */
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $tenant = TenantContext::get();
        $membership = $tenant && $request->user() ? $request->user()->membershipEn($tenant) : null;

        abort_if(! $membership || ! in_array($membership->rol, $roles, true), 403);

        return $next($request);
    }
}
