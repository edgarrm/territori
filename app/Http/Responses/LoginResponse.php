<?php

namespace App\Http\Responses;

use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Laravel\Fortify\Contracts\LoginResponse as LoginResponseContract;

class LoginResponse implements LoginResponseContract
{
    public function toResponse($request)
    {
        if (TenantContext::get()) {
            // Tenant ya resuelto por subdominio antes del login.
            return redirect()->intended(route('dashboard', absolute: false));
        }

        $user = $request->user();
        abort_unless($user instanceof User, 403);

        $memberships = $user->memberships()->where('activo', true)->get();

        if ($memberships->isEmpty()) {
            return redirect()->route('campanas.sin-membership');
        }

        if ($memberships->count() === 1) {
            $request->session()->put('tenant_id', $memberships->first()->tenant_id);

            return redirect()->intended(route('dashboard', absolute: false));
        }

        return redirect()->route('campanas.seleccionar');
    }
}
