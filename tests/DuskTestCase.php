<?php

namespace Tests;

use App\Models\AvisoPrivacidad;
use App\Models\Membership;
use App\Models\Municipio;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\CartografiaSeeder;
use Facebook\WebDriver\Chrome\ChromeOptions;
use Facebook\WebDriver\Remote\DesiredCapabilities;
use Facebook\WebDriver\Remote\RemoteWebDriver;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Collection;
use Laravel\Dusk\Browser;
use Laravel\Dusk\TestCase as BaseTestCase;
use PHPUnit\Framework\Attributes\BeforeClass;

abstract class DuskTestCase extends BaseTestCase
{
    use DatabaseTruncation;

    /**
     * Tablas de sistema de PostGIS que no deben truncarse entre tests.
     *
     * @var array<int, string>
     */
    protected $exceptTables = ['spatial_ref_sys'];

    /**
     * Prepare for Dusk test execution.
     */
    #[BeforeClass]
    public static function prepare(): void
    {
        if (! static::runningInSail()) {
            static::startChromeDriver(['--port=9515']);
        }
    }

    /**
     * Create the RemoteWebDriver instance.
     */
    protected function driver(): RemoteWebDriver
    {
        $options = (new ChromeOptions)->addArguments(collect([
            $this->shouldStartMaximized() ? '--start-maximized' : '--window-size=1920,1080',
            '--disable-search-engine-choice-screen',
            '--disable-smooth-scrolling',
        ])->unless($this->hasHeadlessDisabled(), function (Collection $items) {
            return $items->merge([
                '--disable-gpu',
                '--headless=new',
            ]);
        })->all());

        return RemoteWebDriver::create(
            $_ENV['DUSK_DRIVER_URL'] ?? env('DUSK_DRIVER_URL') ?? 'http://localhost:9515',
            DesiredCapabilities::chrome()->setCapability(
                ChromeOptions::CAPABILITY, $options
            )
        );
    }

    /**
     * Viewport de teléfono (iPhone 14) para las pruebas de responsividad.
     *
     * @var array{0: int, 1: int}
     */
    protected array $viewportMovil = [390, 844];

    /**
     * Falla si la página desborda a lo ancho: en móvil eso obliga al usuario a
     * hacer scroll horizontal para leer el contenido.
     */
    protected function assertSinScrollHorizontal(Browser $browser): void
    {
        $desborde = $browser->script(
            'return document.documentElement.scrollWidth - window.innerWidth;'
        )[0];

        $this->assertLessThanOrEqual(
            1,
            $desborde,
            "La página desborda {$desborde}px a lo ancho del viewport."
        );
    }

    /**
     * Siembra la cartografía de prueba y devuelve el municipio (clave 12).
     */
    protected function sembrarCartografia(): Municipio
    {
        if (Municipio::query()->where('clave', 12)->doesntExist()) {
            (new CartografiaSeeder)->run(base_path('tests/Fixtures/cartografia/99-test'));
        }

        return Municipio::query()->where('clave', 12)->firstOrFail();
    }

    /**
     * Crea una campaña completa (tenant + usuario + membership + aviso) lista
     * para entrar al sistema.
     *
     * @return array{0: Tenant, 1: User, 2: Membership, 3: Municipio, 4: AvisoPrivacidad}
     */
    protected function crearCampana(string $rol = 'admin', string $password = 'password'): array
    {
        $municipio = $this->sembrarCartografia();
        $tenant = Tenant::factory()->create(['municipio_id' => $municipio->id]);
        $user = User::factory()->create(['password' => bcrypt($password)]);
        $membership = Membership::factory()->for($tenant)->for($user)->create(['rol' => $rol, 'activo' => true]);
        // El proceso de PHPUnit no comparte contenedor con el servidor HTTP que
        // maneja el navegador: tenant_id ya no es fillable (A5), así que hay que
        // fijar el TenantContext aquí para que los modelos tenant-scoped creados
        // en este proceso (aviso y cualquier fixture adicional del test) lo tomen.
        TenantContext::set($tenant);
        $aviso = AvisoPrivacidad::factory()->create(['tenant_id' => $tenant->id]);

        return [$tenant, $user, $membership, $municipio, $aviso];
    }

    /**
     * Satisface `RequiereDobleFactor` (dashboard, export, bandeja ARCO) sin
     * activar el reto real de Fortify en el login: ese solo dispara si
     * `two_factor_secret` también está presente (ver
     * `TwoFactorAuthenticatable::twoFactorEnabled()`), y estos tests entran
     * con `loginAs()`, que no pasa por el pipeline de autenticación real.
     */
    protected function confirmarDobleFactor(User $user): void
    {
        $user->forceFill(['two_factor_confirmed_at' => now()])->save();
    }
}
