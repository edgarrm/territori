<?php

namespace Tests\Feature\Campana;

use App\Models\Partido;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ConfiguracionCampanaTest extends TestCase
{
    use RefreshDatabase;

    private const CATEGORIAS_DEFAULT = [
        ['nombre' => 'Ganada franca', 'color' => '#15803d', 'umbral' => 30, 'slug' => 'ganada-franca'],
        ['nombre' => 'Competida', 'color' => '#f59e0b', 'umbral' => 1, 'slug' => 'competida'],
        ['nombre' => 'Empatada', 'color' => '#6b7280', 'umbral' => 0, 'slug' => 'empatada'],
        ['nombre' => 'Perdida', 'color' => '#dc2626', 'umbral' => null, 'slug' => 'perdida'],
    ];

    public function test_tenant_sin_settings_devuelve_defaults(): void
    {
        $tenant = Tenant::factory()->create();

        $this->assertNull($tenant->partido_id);
        $this->assertSame([
            'umbral_alfa' => 1000,
            'umbral_beta' => 500,
            'modo_calculo_competitividad' => 'votos',
            'categorias_competitividad' => self::CATEGORIAS_DEFAULT,
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
            'settings' => ['umbral_alfa' => 1200],
        ]);

        $configuracion = $tenant->configuracion();

        $this->assertSame(1200, $configuracion['umbral_alfa']);
        $this->assertSame(500, $configuracion['umbral_beta']);
        $this->assertTrue($configuracion['indicadores']['competitividad']);
        $this->assertSame(self::CATEGORIAS_DEFAULT, $configuracion['categorias_competitividad']);
    }

    public function test_categorias_personalizadas_reemplazan_por_completo_al_default(): void
    {
        $tenant = Tenant::factory()->create([
            'settings' => [
                'categorias_competitividad' => [
                    ['nombre' => 'Ganando', 'color' => '#15803d', 'umbral' => 1],
                    ['nombre' => 'Perdiendo', 'color' => '#dc2626', 'umbral' => null],
                ],
            ],
        ]);

        $categorias = $tenant->configuracion()['categorias_competitividad'];

        // Reemplazo total: NO deben "resucitar" las categorías 3-4 del default
        // por un merge recursivo por índice (array_replace_recursive).
        $this->assertCount(2, $categorias);
        $this->assertSame(['Ganando', 'Perdiendo'], array_column($categorias, 'nombre'));
        $this->assertSame(['ganando', 'perdiendo'], array_column($categorias, 'slug'));
    }

    public function test_settings_legado_con_umbral_ganada_franca_suelto_se_normaliza_on_read(): void
    {
        $tenant = Tenant::factory()->create([
            'settings' => ['umbral_ganada_franca' => 50],
        ]);

        $categorias = $tenant->configuracion()['categorias_competitividad'];

        $this->assertSame(50, $categorias[0]['umbral']);
        $this->assertSame('Ganada franca', $categorias[0]['nombre']);
        $this->assertSame(
            array_column(self::CATEGORIAS_DEFAULT, 'nombre'),
            array_column($categorias, 'nombre'),
        );
    }

    public function test_aislamiento_entre_tenants(): void
    {
        $partido = Partido::factory()->create();
        $tenantA = Tenant::factory()->create(['partido_id' => $partido->id, 'settings' => ['umbral_alfa' => 1200]]);
        $tenantB = Tenant::factory()->create();

        $this->assertSame(1200, $tenantA->configuracion()['umbral_alfa']);
        $this->assertSame(1000, $tenantB->configuracion()['umbral_alfa']);
        $this->assertNull($tenantB->partido_id);
    }
}
