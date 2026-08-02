<?php

namespace App\Providers;

use App\Events\ElectorCapturado;
use App\Listeners\ActualizarCoberturaAlCapturar;
use Carbon\CarbonImmutable;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use RuntimeException;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();

        Event::listen(ElectorCapturado::class, ActualizarCoberturaAlCapturar::class);
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        $this->configurarProxiesDeConfianza();

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );

        $this->verificarConfiguracionDeProduccion();
    }

    /**
     * Detrás de un proxy TLS (ngrok en desarrollo, balanceador en Cloud) el
     * request llega por http; sin confiar en el proxy, Laravel generaría URLs
     * http:// y el login viajaría en claro hasta el borde del túnel.
     *
     * No se confían X-Forwarded-Host ni -Prefix: con ellos un cliente envenena
     * las URLs que genera route(), incluido el enlace firmado de reset de
     * contraseña, y hace que el token viaje a su propio dominio.
     *
     * Va aquí y no en bootstrap/app.php porque allí el contenedor de
     * configuración todavía no existe, y `env()` fuera de config/ devuelve null
     * en cuanto se ejecuta `config:cache`.
     */
    protected function configurarProxiesDeConfianza(): void
    {
        $proxies = config('app.trusted_proxies');

        TrustProxies::at(is_string($proxies) && str_contains($proxies, ',')
            ? array_map(trim(...), explode(',', $proxies))
            : $proxies,
        );

        TrustProxies::withHeaders(
            Request::HEADER_X_FORWARDED_FOR
            | Request::HEADER_X_FORWARDED_PROTO
            | Request::HEADER_X_FORWARDED_PORT,
        );
    }

    /**
     * Impide arrancar en producción con una configuración que expone datos
     * personales. Toda la política de seguridad de la app está condicionada a
     * `isProduction()`, así que un despliegue con APP_ENV mal puesto la degrada
     * en silencio; y sin cookie `Secure` la sesión viaja por HTTP plano. Es
     * preferible fallar de forma ruidosa a servir la app en ese estado.
     *
     * @throws RuntimeException
     */
    protected function verificarConfiguracionDeProduccion(): void
    {
        if (! app()->isProduction()) {
            return;
        }

        $problemas = [];

        if (config('app.debug')) {
            $problemas[] = 'APP_DEBUG debe ser false (expone trazas con datos internos).';
        }

        if (config('session.secure') !== true) {
            $problemas[] = 'SESSION_SECURE_COOKIE debe ser true (cookie de sesión solo por HTTPS).';
        }

        if (config('session.encrypt') !== true) {
            $problemas[] = 'SESSION_ENCRYPT debe ser true (payload de sesión cifrado en reposo).';
        }

        if ($problemas !== []) {
            throw new RuntimeException(
                "Configuración de producción insegura para una app con datos personales:\n- "
                .implode("\n- ", $problemas)
            );
        }
    }
}
