<?php

namespace Tests\Feature\Seguridad;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * La cabecera `Host` la escribe el cliente y Laravel no la valida por su cuenta:
 * `UrlGenerator::formatRoot()` toma la raíz de `$request->root()`, y
 * `ResetPassword::resetUrl()` es literalmente `url(route('password.reset', ...))`.
 *
 * Sin `TrustHosts`, un `POST /forgot-password` con `Host: atacante.com` produce
 * un correo legítimo, enviado por el dominio real, cuyo enlace lleva el token de
 * reset al servidor del atacante: toma de cuenta completa.
 *
 * No lo cubría el test de `X-Forwarded-Host` (RutasDesarrolloTest): esa cabecera
 * está excluida de `TrustProxies::withHeaders()`, pero eso solo tapa la variante
 * que llega por el proxy, no la cabecera `Host` cruda.
 *
 * `TrustHosts::shouldSpecifyTrustedHosts()` se apaga en `local` y bajo tests, así
 * que aquí se fuerza el entorno a `production` para ejercer el control de verdad.
 */
class CabeceraHostTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'app.url' => 'https://territori.app',
            'app.tenant_domain' => 'campanas.territori.app',
        ]);

        app()->detectEnvironment(fn (): string => 'production');
    }

    protected function tearDown(): void
    {
        // `setTrustedHosts` es estado estático de Symfony: sin limpiarlo, el
        // resto de la suite quedaría rechazando el host por defecto de los tests.
        Request::setTrustedHosts([]);

        parent::tearDown();
    }

    /**
     * El host se fija con una URL absoluta, no con `withHeader('Host', ...)`:
     * `SymfonyRequest::create()` sobrescribe `HTTP_HOST` con el host de la URI
     * (Request.php:408), así que la cabecera suelta no controla nada y el test
     * pasaría por la razón equivocada.
     */
    public function test_un_host_ajeno_es_rechazado(): void
    {
        // Sin CSRF de por medio: forzar el entorno a `production` desactiva la
        // exención que `ValidateCsrfToken` se aplica bajo tests, y un 419 haría
        // pasar el test sin demostrar que el rechazo lo provoca TrustHosts.
        $this->withoutMiddleware(PreventRequestForgery::class)
            ->post('https://atacante.com/forgot-password', ['email' => 'alguien@territori.app'])
            ->assertBadRequest();
    }

    public function test_un_host_que_solo_contiene_el_dominio_no_basta(): void
    {
        // El patrón va anclado a propósito: `Request::setTrustedHosts()` envuelve
        // cada entrada en `{...}i` y la evalúa con `preg_match` SIN anclarla, así
        // que un patrón laxo dejaría pasar este host.
        $this->get('https://campanas.territori.app.atacante.com/login')
            ->assertBadRequest();
    }

    public function test_el_dominio_de_la_app_sigue_siendo_valido(): void
    {
        $this->get('https://territori.app/login')->assertOk();
    }

    public function test_los_subdominios_de_campana_siguen_siendo_validos(): void
    {
        // ResolveTenant::tenantPorSubdominio() resuelve la campaña desde
        // {campana}.{tenant_domain}: si TrustHosts los rechazara, el
        // enrutamiento multi-campaña dejaría de funcionar.
        $this->get('https://mazatlan.campanas.territori.app/login')->assertOk();
    }

    public function test_el_enlace_de_reset_se_genera_contra_el_dominio_real(): void
    {
        Notification::fake();

        User::factory()->create(['email' => 'alguien@territori.app']);

        // Petición legítima: el enlace debe salir con el dominio de la app.
        $this->withoutMiddleware(PreventRequestForgery::class)
            ->post('https://territori.app/forgot-password', ['email' => 'alguien@territori.app'])
            ->assertSessionHasNoErrors();

        Notification::assertSentTo(
            User::query()->where('email', 'alguien@territori.app')->sole(),
            ResetPassword::class,
            function (ResetPassword $notificacion, array $canales, User $user): bool {
                $enlace = $notificacion->toMail($user)->actionUrl;

                $this->assertStringStartsWith('https://territori.app/', $enlace);

                return true;
            },
        );
    }

    public function test_un_host_ajeno_no_llega_a_generar_enlace_de_reset(): void
    {
        Notification::fake();

        User::factory()->create(['email' => 'alguien@territori.app']);

        $this->withoutMiddleware(PreventRequestForgery::class)
            ->post('https://atacante.com/forgot-password', ['email' => 'alguien@territori.app'])
            ->assertBadRequest();

        // La petición se rechaza antes de entrar a la aplicación: nunca se
        // genera —ni se envía— un enlace con el token hacia el dominio ajeno.
        Notification::assertNothingSent();
    }
}
