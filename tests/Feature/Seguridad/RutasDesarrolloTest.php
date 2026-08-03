<?php

namespace Tests\Feature\Seguridad;

use App\Models\User;
use App\Providers\AppServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * `laravel/dusk` y `laravel/boost` registran rutas sin autenticación en cuanto
 * el entorno no es `production`. Con la app publicada por un túnel, eso deja
 * `/_dusk/login/{id}` como bypass total de autenticación hacia el padrón.
 */
class RutasDesarrolloTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_de_dusk_no_autentica_fuera_de_los_entornos_de_prueba(): void
    {
        $user = User::factory()->create();

        app()->detectEnvironment(fn (): string => 'local');

        $response = $this->get("/_dusk/login/{$user->id}");

        $response->assertNotFound();
        $this->assertGuest();
    }

    public function test_logout_y_user_de_dusk_tampoco_responden_en_local(): void
    {
        app()->detectEnvironment(fn (): string => 'local');

        $this->get('/_dusk/logout')->assertNotFound();
        $this->get('/_dusk/user')->assertNotFound();
    }

    public function test_boost_no_acepta_logs_desde_una_ip_remota(): void
    {
        app()->detectEnvironment(fn (): string => 'local');

        // REMOTE_ADDR es la dirección real del socket: a través de un túnel
        // nunca es loopback, y el cliente no la puede falsear con cabeceras.
        $response = $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.7'])
            ->postJson('/_boost/browser-logs', ['logs' => []]);

        $response->assertNotFound();
    }

    public function test_x_forwarded_for_no_hace_pasar_a_boost_por_local(): void
    {
        app()->detectEnvironment(fn (): string => 'local');

        $response = $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.7'])
            ->withHeaders(['X-Forwarded-For' => '127.0.0.1'])
            ->postJson('/_boost/browser-logs', ['logs' => []]);

        $response->assertNotFound();
    }

    public function test_x_forwarded_host_no_envenena_las_urls_generadas(): void
    {
        $response = $this->withHeaders(['X-Forwarded-Host' => 'atacante.example'])
            ->get(route('login'));

        $response->assertOk();
        $this->assertStringNotContainsString('atacante.example', url()->current());
    }

    /**
     * Prueba que `config('app.trusted_proxies')` se lee de verdad en
     * bootstrap/app.php. Si devolviera null (el fallo que tenía con `env()` y
     * la config cacheada) no habría proxies de confianza y X-Forwarded-Proto
     * se ignoraría, rompiendo la terminación TLS detrás del balanceador.
     */
    public function test_x_forwarded_proto_sigue_honrandose_tras_un_proxy(): void
    {
        $response = $this->withHeaders(['X-Forwarded-Proto' => 'https'])
            ->get(route('login'));

        $response->assertOk();
        $this->assertTrue(request()->isSecure());
    }

    /**
     * El default de `trusted_proxies` era '*', que falla abierto: con él
     * cualquier cliente cuenta como proxy y `X-Forwarded-For` decide la IP que
     * ve la app. El limiter de login firma por `email|ip`, así que rotar la
     * cabecera daba intentos ilimitados; y la IP de `bitacora_accesos` — la
     * traza de accesos a datos personales — quedaba escrita por el atacante.
     */
    public function test_x_forwarded_for_no_altera_la_ip_sin_proxies_de_confianza(): void
    {
        config(['app.trusted_proxies' => null]);
        (new AppServiceProvider(app()))->boot();

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.7'])
            ->withHeaders(['X-Forwarded-For' => '198.51.100.4'])
            ->get(route('login'))
            ->assertOk();

        $this->assertSame('203.0.113.7', request()->ip());
    }

    public function test_produccion_no_arranca_con_proxies_comodin(): void
    {
        app()->detectEnvironment(fn (): string => 'production');
        config([
            'app.debug' => false,
            'session.secure' => true,
            'session.encrypt' => true,
            'app.trusted_proxies' => '*',
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('TRUSTED_PROXIES');

        (new AppServiceProvider(app()))->boot();
    }

    public function test_produccion_no_arranca_sin_proxies_de_confianza(): void
    {
        app()->detectEnvironment(fn (): string => 'production');
        config([
            'app.debug' => false,
            'session.secure' => true,
            'session.encrypt' => true,
            'app.trusted_proxies' => null,
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('TRUSTED_PROXIES');

        (new AppServiceProvider(app()))->boot();
    }

    public function test_produccion_no_arranca_sin_cookie_de_sesion_segura(): void
    {
        app()->detectEnvironment(fn (): string => 'production');
        config(['app.debug' => false, 'session.secure' => false, 'session.encrypt' => true]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('SESSION_SECURE_COOKIE');

        (new AppServiceProvider(app()))->boot();
    }

    public function test_produccion_no_arranca_con_debug_activo_ni_sesion_sin_cifrar(): void
    {
        app()->detectEnvironment(fn (): string => 'production');
        config(['app.debug' => true, 'session.secure' => true, 'session.encrypt' => false]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('APP_DEBUG');

        (new AppServiceProvider(app()))->boot();
    }

    public function test_produccion_bien_configurada_arranca(): void
    {
        app()->detectEnvironment(fn (): string => 'production');
        config(['app.debug' => false, 'session.secure' => true, 'session.encrypt' => true]);

        (new AppServiceProvider(app()))->boot();

        $this->expectNotToPerformAssertions();
    }
}
