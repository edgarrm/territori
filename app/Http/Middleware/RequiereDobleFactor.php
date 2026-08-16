<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * I8 de la auditoría: la 2FA está disponible y bien configurada, pero ningún
 * rol la exige — ni siquiera admin ni quien exporta el padrón completo.
 * Coordinador y admin concentran el acceso de más alcance (export del
 * padrón, bandeja ARCO, alta de brigadistas, análisis); sin 2FA, una sola
 * contraseña filtrada basta para exponerlo todo.
 *
 * Va después de `rol:` en las rutas donde se aplica: solo tiene sentido
 * exigir 2FA para roles que ya pasaron ese filtro. Un `abort_if` simple, como
 * en `EnsureRol`, en vez de redirigir: la ruta de export y la de atender ARCO
 * también se llaman por XHR/descarga directa, no solo por navegación.
 */
class RequiereDobleFactor
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_if($request->user()?->two_factor_confirmed_at === null, 403);

        return $next($request);
    }
}
