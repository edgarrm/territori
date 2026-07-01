<?php

namespace App\Http\Responses;

use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Laravel\Fortify\Contracts\LoginResponse as LoginResponseContract;

class LoginResponse implements LoginResponseContract
{
    public function toResponse($request)
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        if ($tenant = TenantContext::get()) {
            // Tenant ya resuelto por subdominio antes del login.
            $membership = $user->membershipEn($tenant);

            return redirect()->intended(route($membership?->rutaInicial() ?? 'dashboard', absolute: false));
        }

        $memberships = $user->memberships()->where('activo', true)->get();

        if ($memberships->isEmpty()) {
            return redirect()->route('campanas.sin-membership');
        }

        if ($memberships->count() === 1) {
            $membership = $memberships->first();
            $request->session()->put('tenant_id', $membership->tenant_id);

            return redirect()->intended(route($membership->rutaInicial(), absolute: false));
        }

        return redirect()->route('campanas.seleccionar');
    }
}
