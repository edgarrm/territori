<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Application Name
    |--------------------------------------------------------------------------
    |
    | This value is the name of your application, which will be used when the
    | framework needs to place the application's name in a notification or
    | other UI elements where an application name needs to be displayed.
    |
    */

    'name' => env('APP_NAME', 'Laravel'),

    /*
    |--------------------------------------------------------------------------
    | Application Environment
    |--------------------------------------------------------------------------
    |
    | This value determines the "environment" your application is currently
    | running in. This may determine how you prefer to configure various
    | services the application utilizes. Set this in your ".env" file.
    |
    */

    'env' => env('APP_ENV', 'production'),

    /*
    |--------------------------------------------------------------------------
    | Application Debug Mode
    |--------------------------------------------------------------------------
    |
    | When your application is in debug mode, detailed error messages with
    | stack traces will be shown on every error that occurs within your
    | application. If disabled, a simple generic error page is shown.
    |
    */

    'debug' => (bool) env('APP_DEBUG', false),

    /*
    |--------------------------------------------------------------------------
    | Application URL
    |--------------------------------------------------------------------------
    |
    | This URL is used by the console to properly generate URLs when using
    | the Artisan command line tool. You should set this to the root of
    | the application so that it's available within Artisan commands.
    |
    */

    'url' => env('APP_URL', 'http://localhost'),

    /*
    |--------------------------------------------------------------------------
    | Tenant Domain
    |--------------------------------------------------------------------------
    |
    | Dominio base usado para resolver el subdominio de una campaña
    | (ej. "campania.territori.app" con tenant_domain=territori.app).
    |
    */

    'tenant_domain' => env('TENANT_DOMAIN'),

    /*
    |--------------------------------------------------------------------------
    | Trusted Proxies
    |--------------------------------------------------------------------------
    |
    | Proxies de los que se aceptan las cabeceras X-Forwarded-*. Con '*'
    | cualquier cliente cuenta como proxy y puede falsear su IP para saltarse
    | el rate limit de login, así que en producción debe ser el CIDR real del
    | balanceador. Lista separada por comas o '*'.
    |
    | Sin default a propósito: el valor por omisión era '*', que falla ABIERTO.
    | Con él, `X-Forwarded-For` controlaba por completo la IP que ve la app —
    | el limiter de login firma por `email|ip`, así que rotar la cabecera daba
    | fuerza bruta ilimitada, y la IP de `bitacora_accesos` (la traza LFPDPPP)
    | quedaba escrita por el atacante. `null` = no se confía en ningún proxy;
    | `AppServiceProvider::verificarConfiguracionDeProduccion()` además impide
    | arrancar en producción si sigue vacío o en '*'.
    |
    */

    'trusted_proxies' => env('TRUSTED_PROXIES'),

    /*
    |--------------------------------------------------------------------------
    | Retención de datos personales (LFPDPPP art. 11)
    |--------------------------------------------------------------------------
    |
    | Los datos personales deben suprimirse cuando dejan de ser necesarios para
    | la finalidad que los justificó. `territori:purgar-datos` aplica estos dos
    | plazos, en días:
    |
    | - electores_cancelados_dias: cuánto se conserva la fila ya scrubbeada de
    |   una Cancelación ARCO (solo ids y fechas, sin dato personal) antes del
    |   borrado físico. Existe para poder acreditar que la cancelación se
    |   ejecutó; pasado el plazo ya no aporta nada.
    | - campana_cerrada_dias: cuánto sobreviven los electores de una campaña en
    |   estado 'cerrada' antes de que se les borre la PII. Al cerrar la campaña
    |   se agota la finalidad del tratamiento.
    |
    */

    'retencion' => [
        'electores_cancelados_dias' => (int) env('RETENCION_ELECTORES_CANCELADOS_DIAS', 365),
        'campana_cerrada_dias' => (int) env('RETENCION_CAMPANA_CERRADA_DIAS', 180),
    ],

    /*
    |--------------------------------------------------------------------------
    | Application Timezone
    |--------------------------------------------------------------------------
    |
    | Here you may specify the default timezone for your application, which
    | will be used by the PHP date and date-time functions. The timezone
    | is set to "UTC" by default as it is suitable for most use cases.
    |
    */

    'timezone' => 'UTC',

    /*
    |--------------------------------------------------------------------------
    | Application Locale Configuration
    |--------------------------------------------------------------------------
    |
    | The application locale determines the default locale that will be used
    | by Laravel's translation / localization methods. This option can be
    | set to any locale for which you plan to have translation strings.
    |
    */

    'locale' => env('APP_LOCALE', 'en'),

    'fallback_locale' => env('APP_FALLBACK_LOCALE', 'en'),

    'faker_locale' => env('APP_FAKER_LOCALE', 'en_US'),

    /*
    |--------------------------------------------------------------------------
    | Encryption Key
    |--------------------------------------------------------------------------
    |
    | This key is utilized by Laravel's encryption services and should be set
    | to a random, 32 character string to ensure that all encrypted values
    | are secure. You should do this prior to deploying the application.
    |
    */

    'cipher' => 'AES-256-CBC',

    'key' => env('APP_KEY'),

    'previous_keys' => [
        ...array_filter(
            explode(',', (string) env('APP_PREVIOUS_KEYS', '')),
        ),
    ],

    /*
    |--------------------------------------------------------------------------
    | Maintenance Mode Driver
    |--------------------------------------------------------------------------
    |
    | These configuration options determine the driver used to determine and
    | manage Laravel's "maintenance mode" status. The "cache" driver will
    | allow maintenance mode to be controlled across multiple machines.
    |
    | Supported drivers: "file", "cache", "array"
    |
    */

    'maintenance' => [
        'driver' => env('APP_MAINTENANCE_DRIVER', 'file'),
        'store' => env('APP_MAINTENANCE_STORE', 'database'),
    ],

];
