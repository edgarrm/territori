<?php

use App\Http\Middleware\BloqueaRutasDeDesarrollo;
use App\Http\Middleware\EnsureRol;
use App\Http\Middleware\EnsureTenantSeleccionado;
use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleInertiaRequests;
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

        // Los proxies de confianza se configuran en AppServiceProvider::boot():
        // aquí todavía no existe el contenedor de configuración, y leerlos con
        // env() daría null en cuanto se ejecute `config:cache`.

        $middleware->encryptCookies(except: ['appearance', 'sidebar_state']);

        $middleware->web(append: [
            HandleAppearance::class,
            ResolveTenant::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);

        $middleware->alias([
            'rol' => EnsureRol::class,
            'tenant' => EnsureTenantSeleccionado::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );
    })->create();
