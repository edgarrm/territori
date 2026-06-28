<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureTenantSeleccionado
{
    /**
     * Garantiza una campaña (tenant) activa antes de entrar a las rutas de
     * dominio. Si no la hay, enruta según cuántas campañas activas tenga el
     * usuario: 0 -> sin-membership, 1 -> auto-selecciona, 2+ -> selector.
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (TenantContext::get()) {
            return $next($request);
        }

        $user = $request->user();

        if (! $user instanceof User) {
            return $next($request);
        }

        $membershipsActivas = $user->memberships()->where('activo', true)->get();

        if ($membershipsActivas->isEmpty()) {
            return redirect()->route('campanas.sin-membership');
        }

        if ($membershipsActivas->count() === 1) {
            // Auto-selecciona y reentra: ResolveTenant resolverá el tenant de
            // sesión en el siguiente request, dejando los props compartidos OK.
            $request->session()->put('tenant_id', $membershipsActivas->first()->tenant_id);

            return redirect($request->fullUrl());
        }

        return redirect()->route('campanas.seleccionar');
    }
}
