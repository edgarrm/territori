<?php

namespace Tests\Feature\Zonas;

use App\Models\Tenant;
use App\Models\Zona;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ImportarZonasCommandTest extends TestCase
{
    use RefreshDatabase;

    private string $fixturesDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fixturesDir = base_path('tests/Fixtures/zonas');
    }

    // --- 4. FeatureCollection de 5 features -> 5 Zona; re-correr no duplica ---
    public function test_importa_ciudades_y_es_idempotente(): void
    {
        $tenant = Tenant::factory()->create();

        $this->artisan('territori:importar-zonas', [
            'archivo' => "{$this->fixturesDir}/ciudades.geojson",
            '--nivel' => 'ciudad',
            '--tenant' => $tenant->id,
        ])->assertSuccessful();

        TenantContext::set($tenant);
        $this->assertSame(5, Zona::query()->count());
        $this->assertSame(5, Zona::query()->where('tipo', 'ciudad')->count());
        $this->assertSame(5, Zona::query()->where('fuente', 'importada')->count());

        // Segunda corrida: mismo resultado, no duplica.
        $this->artisan('territori:importar-zonas', [
            'archivo' => "{$this->fixturesDir}/ciudades.geojson",
            '--nivel' => 'ciudad',
            '--tenant' => $tenant->id,
        ])->assertSuccessful();

        TenantContext::set($tenant);
        $this->assertSame(5, Zona::query()->count());
    }

    // --- 5. --padre-clave resuelve zona_padre_id por clave ---
    public function test_padre_clave_resuelve_zona_padre_id(): void
    {
        $tenant = Tenant::factory()->create();

        $this->artisan('territori:importar-zonas', [
            'archivo' => "{$this->fixturesDir}/paises.geojson",
            '--nivel' => 'pais',
            '--tenant' => $tenant->id,
        ])->assertSuccessful();

        $this->artisan('territori:importar-zonas', [
            'archivo' => "{$this->fixturesDir}/estados.geojson",
            '--nivel' => 'estado',
            '--tenant' => $tenant->id,
            '--padre-clave' => 'PAIS1',
        ])->assertSuccessful();

        TenantContext::set($tenant);
        $pais = Zona::query()->where('clave', 'PAIS1')->firstOrFail();
        $estados = Zona::query()->where('tipo', 'estado')->get();

        $this->assertCount(2, $estados);

        foreach ($estados as $estado) {
            $this->assertSame($pais->id, $estado->zona_padre_id);
        }
    }

    public function test_padre_clave_inexistente_falla(): void
    {
        $tenant = Tenant::factory()->create();

        $this->artisan('territori:importar-zonas', [
            'archivo' => "{$this->fixturesDir}/estados.geojson",
            '--nivel' => 'estado',
            '--tenant' => $tenant->id,
            '--padre-clave' => 'NO-EXISTE',
        ])->assertFailed();

        TenantContext::set($tenant);
        $this->assertSame(0, Zona::query()->count());
    }

    public function test_tenant_inexistente_falla(): void
    {
        $this->artisan('territori:importar-zonas', [
            'archivo' => "{$this->fixturesDir}/ciudades.geojson",
            '--nivel' => 'ciudad',
            '--tenant' => 999999,
        ])->assertFailed();
    }

    public function test_actualiza_nombre_sin_duplicar_al_cambiar_el_geojson(): void
    {
        $tenant = Tenant::factory()->create();

        $this->artisan('territori:importar-zonas', [
            'archivo' => "{$this->fixturesDir}/paises.geojson",
            '--nivel' => 'pais',
            '--tenant' => $tenant->id,
        ])->assertSuccessful();

        // Reimportar el mismo archivo (mismo nombre/clave): sigue siendo 1 fila.
        $this->artisan('territori:importar-zonas', [
            'archivo' => "{$this->fixturesDir}/paises.geojson",
            '--nivel' => 'pais',
            '--tenant' => $tenant->id,
        ])->assertSuccessful();

        TenantContext::set($tenant);
        $this->assertSame(1, Zona::query()->where('clave', 'PAIS1')->count());
    }
}
