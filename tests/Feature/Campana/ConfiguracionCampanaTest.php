<?php

namespace Tests\Feature\Campana;

use App\Models\Partido;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ConfiguracionCampanaTest extends TestCase
{
    use RefreshDatabase;

    public function test_tenant_sin_settings_devuelve_defaults(): void
    {
        $tenant = Tenant::factory()->create();

        $this->assertNull($tenant->partido_id);
        $this->assertSame([
            'umbral_ganada_franca' => 30,
            'umbral_alfa' => 1000,
            'umbral_beta' => 500,
            'indicadores' => [
                'competitividad' => true,
                'tipo_seccion' => true,
                'indice_neutral' => true,
                'oportunidad' => true,
            ],
        ], $tenant->configuracion());
    }

    public function test_tenant_con_settings_parciales_hace_merge_sobre_defaults(): void
    {
        $tenant = Tenant::factory()->create([
            'settings' => ['umbral_ganada_franca' => 50],
        ]);

        $configuracion = $tenant->configuracion();

        $this->assertSame(50, $configuracion['umbral_ganada_franca']);
        $this->assertSame(1000, $configuracion['umbral_alfa']);
        $this->assertTrue($configuracion['indicadores']['competitividad']);
    }

    public function test_aislamiento_entre_tenants(): void
    {
        $partido = Partido::factory()->create();
        $tenantA = Tenant::factory()->create(['partido_id' => $partido->id, 'settings' => ['umbral_ganada_franca' => 50]]);
        $tenantB = Tenant::factory()->create();

        $this->assertSame(50, $tenantA->configuracion()['umbral_ganada_franca']);
        $this->assertSame(30, $tenantB->configuracion()['umbral_ganada_franca']);
        $this->assertNull($tenantB->partido_id);
    }
}
