<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Cierra el auto-registro cuando la instancia queda expuesta a internet
 * (REGISTRO_PUBLICO=false): las altas de miembros las hace la gestión desde
 * /brigadistas, no un formulario público.
 *
 * Se bloquea el acceso en vez de quitar Features::registration() porque
 * Wayfinder genera los helpers de TypeScript a partir de las rutas registradas:
 * si la ruta dependiera del flag, un build hecho con el registro apagado
 * quedaría roto al encenderlo.
 */
class BloqueaRegistroPublico
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        abort_if(
            $request->routeIs('register', 'register.store') && ! config('fortify.registro_publico'),
            404,
        );

        return $next($request);
    }
}
