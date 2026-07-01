<?php

namespace App\Http\Controllers;

use App\Models\Membership;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CampanaSelectorController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        $memberships = $user->memberships()->where('activo', true)->with('tenant')->get();

        return Inertia::render('campanas/Seleccionar', [
            'campanas' => $memberships->map(fn (Membership $m) => [
                'tenant_id' => $m->tenant_id,
                'nombre' => $m->tenant->marca_nombre ?? $m->tenant->nombre,
                'rol' => $m->rol,
            ]),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        $data = $request->validate([
            'tenant_id' => ['required', 'integer'],
        ]);

        $membership = $user->memberships()
            ->where('tenant_id', $data['tenant_id'])
            ->where('activo', true)
            ->first();

        abort_if(! $membership, 403);

        $request->session()->put('tenant_id', $membership->tenant_id);

        return redirect()->route($membership->rutaInicial());
    }

    public function sinMembership(): Response
    {
        return Inertia::render('campanas/SinMembership');
    }
}
