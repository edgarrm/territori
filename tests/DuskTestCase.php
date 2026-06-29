<?php

namespace Tests;

use App\Models\AvisoPrivacidad;
use App\Models\Membership;
use App\Models\Municipio;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\CartografiaSeeder;
use Facebook\WebDriver\Chrome\ChromeOptions;
use Facebook\WebDriver\Remote\DesiredCapabilities;
use Facebook\WebDriver\Remote\RemoteWebDriver;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Collection;
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
        $aviso = AvisoPrivacidad::factory()->create(['tenant_id' => $tenant->id]);

        return [$tenant, $user, $membership, $municipio, $aviso];
    }
}
