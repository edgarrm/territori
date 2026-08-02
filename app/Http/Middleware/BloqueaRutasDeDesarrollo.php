<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Cierra las rutas que paquetes de desarrollo registran fuera de producción y
 * que no exigen autenticación.
 *
 * `laravel/dusk` publica `/_dusk/login/{userId}`, que autentica como cualquier
 * usuario sin contraseña ni segundo factor, y `laravel/boost` publica
 * `/_boost/browser-logs` sin auth ni CSRF. Ambos se registran con la única
 * condición de que el entorno no sea `production`, así que una instancia local
 * expuesta por un túnel (ngrok) queda con un bypass total de autenticación
 * hacia el padrón de electores.
 *
 * No basta con poner APP_ENV=production: la protección no debe depender de una
 * variable de entorno que se puede olvidar. Aquí las rutas se bloquean salvo en
 * los dos casos legítimos, y el de Boost se decide por la dirección real del
 * socket (no por cabeceras de proxy, que el cliente controla).
 */
class BloqueaRutasDeDesarrollo
{
    /**
     * Entornos donde Dusk necesita `/_dusk/*` para su helper `loginAs()`. Dusk
     * arranca la app en un proceso HTTP aparte, así que `runningUnitTests()` es
     * falso ahí y no sirve como condición.
     *
     * @var array<int, string>
     */
    private const ENTORNOS_DE_PRUEBA = ['testing', 'dusk', 'dusk.local'];

    public function handle(Request $request, Closure $next): Response
    {
        if ($request->is('_dusk/*') && ! app()->environment(self::ENTORNOS_DE_PRUEBA)) {
            abort(404);
        }

        if ($request->is('_boost/*') && ! $this->esDesarrolloLocalDirecto($request)) {
            abort(404);
        }

        return $next($request);
    }

    /**
     * Boost sigue disponible para el desarrollo en la propia máquina, pero no a
     * través de un túnel. Se lee `REMOTE_ADDR` directamente para saltarse la
     * resolución de proxies: `X-Forwarded-For` lo escribe el cliente.
     */
    private function esDesarrolloLocalDirecto(Request $request): bool
    {
        return app()->environment('local')
            && in_array($request->server('REMOTE_ADDR'), ['127.0.0.1', '::1'], true);
    }
}
