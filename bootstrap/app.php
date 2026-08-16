<?php

use App\Http\Middleware\BloqueaRutasDeDesarrollo;
use App\Http\Middleware\CabecerasSeguridad;
use App\Http\Middleware\EnsureRol;
use App\Http\Middleware\EnsureTenantSeleccionado;
use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\RequiereDobleFactor;
use App\Http\Middleware\ResolveTenant;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // `_dusk/*` y `_boost/*` se registran solos fuera de producción y no
        // piden autenticación. Va en el stack global (y primero) porque
        // `_boost/browser-logs` no pasa siquiera por el grupo `web`.
        $middleware->prepend(BloqueaRutasDeDesarrollo::class);

        // La cabecera `Host` la escribe el cliente y Laravel no la valida por
        // su cuenta. `UrlGenerator::formatRoot()` toma la raíz de
        // `$request->root()`, así que un `POST /forgot-password` con
        // `Host: atacante.com` produce un correo legítimo cuyo enlace de reset
        // lleva el token al dominio del atacante: toma de cuenta.
        //
        // No basta con lo que ya había: `TrustProxies` deja fuera
        // `X-Forwarded-Host` a propósito, pero eso solo cubre la variante que
        // pasa por el proxy, no la cabecera `Host` cruda.
        //
        // `subdomains: true` es obligatorio: `ResolveTenant::tenantPorSubdominio()`
        // resuelve la campaña desde `{campana}.{tenant_domain}`, así que los
        // subdominios del dominio de la app tienen que seguir siendo válidos.
        // Ese flag ya aporta el patrón de `app.url`; aquí solo se añade el
        // dominio de campañas cuando es distinto.
        //
        // El patrón va anclado y con `preg_quote`: `Request::setTrustedHosts()`
        // envuelve cada entrada en `{...}i` y la evalúa con `preg_match` SIN
        // anclarla, así que un `territori.app` a secas también casaría
        // `territoriXapp.atacante.com`.
        $middleware->trustHosts(at: static function (): array {
            $dominioDeCampanas = config('app.tenant_domain');

            return is_string($dominioDeCampanas) && $dominioDeCampanas !== ''
                ? ['^(.+\.)?'.preg_quote($dominioDeCampanas, '{}').'$']
                : [];
        }, subdomains: true);

        // Los proxies de confianza se configuran en AppServiceProvider::boot():
        // aquí todavía no existe el contenedor de configuración, y leerlos con
        // env() daría null en cuanto se ejecute `config:cache`.

        $middleware->encryptCookies(except: ['appearance', 'sidebar_state']);

        // Ata cada sesión al hash de la contraseña del usuario: al cambiarla,
        // las demás sesiones dejan de validar. Sin esto, una cookie de sesión
        // robada sobrevivía al cambio de contraseña, que es exactamente lo que
        // hace quien sospecha que se la robaron.
        $middleware->authenticateSessions();

        $middleware->web(append: [
            CabecerasSeguridad::class,
            HandleAppearance::class,
            ResolveTenant::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);

        $middleware->alias([
            'rol' => EnsureRol::class,
            'tenant' => EnsureTenantSeleccionado::class,
            '2fa' => RequiereDobleFactor::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );
    })->create();
