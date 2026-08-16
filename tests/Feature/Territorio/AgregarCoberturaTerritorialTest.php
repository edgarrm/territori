<?php

namespace Tests\Feature\Territorio;

use App\Actions\Cobertura\AgregarCoberturaTerritorial;
use App\Models\CoberturaSeccion;
use App\Models\Municipio;
use App\Models\Seccion;
use App\Models\Tenant;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AgregarCoberturaTerritorialTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $municipio = Municipio::factory()->create();
        $this->tenant = Tenant::factory()->create(['municipio_id' => $municipio->id]);
        TenantContext::set($this->tenant);
    }

    /**
     * Criterio 5: cobertura = sum(capturados)/sum(meta), no promedio de los
     * ratios ya calculados por sección (10/20=0.5 y 5/10=0.5 => 15/30=0.5,
     * caso trivial en el que promedio y recálculo coinciden).
     */
    public function test_cobertura_se_recalcula_desde_los_totales(): void
    {
        $seccionA = Seccion::factory()->create(['municipio_id' => $this->tenant->municipio_id]);
        $seccionB = Seccion::factory()->create(['municipio_id' => $this->tenant->municipio_id]);

        CoberturaSeccion::factory()->create([
            'tenant_id' => $this->tenant->id,
            'seccion_id' => $seccionA->id,
            'capturados' => 10,
            'meta' => 20,
        ]);
        CoberturaSeccion::factory()->create([
            'tenant_id' => $this->tenant->id,
            'seccion_id' => $seccionB->id,
            'capturados' => 5,
            'meta' => 10,
        ]);

        $resultado = (new AgregarCoberturaTerritorial)->handle(collect([$seccionA->id, $seccionB->id]));

        $this->assertSame(15, $resultado['capturados']);
        $this->assertSame(30, $resultado['meta']);
        $this->assertEquals(0.5, $resultado['cobertura']);
    }

    /**
     * Con metas desiguales, promedio de ratios (0.5 y 0.3 -> 0.4) difiere del
     * recálculo real (40/120 = 0.3333...): prueba explícita de que NO es un
     * promedio de ratios.
     */
    public function test_cobertura_con_metas_desiguales_no_es_promedio_de_ratios(): void
    {
        $seccionA = Seccion::factory()->create(['municipio_id' => $this->tenant->municipio_id]);
        $seccionB = Seccion::factory()->create(['municipio_id' => $this->tenant->municipio_id]);

        CoberturaSeccion::factory()->create([
            'tenant_id' => $this->tenant->id,
            'seccion_id' => $seccionA->id,
            'capturados' => 10,
            'meta' => 20,
        ]);
        CoberturaSeccion::factory()->create([
            'tenant_id' => $this->tenant->id,
            'seccion_id' => $seccionB->id,
            'capturados' => 30,
            'meta' => 100,
        ]);

        $resultado = (new AgregarCoberturaTerritorial)->handle(collect([$seccionA->id, $seccionB->id]));

        $promedioDeRatios = (0.5 + 0.3) / 2;
        $this->assertNotEquals(round($promedioDeRatios, 4), $resultado['cobertura']);
        $this->assertEqualsWithDelta(40 / 120, $resultado['cobertura'], 0.0001);
    }

    /**
     * penetración/movilización se calculan sobre secciones.lista_nominal (INE),
     * no sobre estadisticas_seccion.lista_nominal_2024.
     */
    public function test_penetracion_y_movilizacion_usan_lista_nominal_de_secciones(): void
    {
        $seccionA = Seccion::factory()->create(['municipio_id' => $this->tenant->municipio_id, 'lista_nominal' => 1000]);
        $seccionB = Seccion::factory()->create(['municipio_id' => $this->tenant->municipio_id, 'lista_nominal' => 500]);

        CoberturaSeccion::factory()->create([
            'tenant_id' => $this->tenant->id,
            'seccion_id' => $seccionA->id,
            'capturados' => 100,
            'meta' => 200,
            'verificados' => 50,
        ]);
        CoberturaSeccion::factory()->create([
            'tenant_id' => $this->tenant->id,
            'seccion_id' => $seccionB->id,
            'capturados' => 50,
            'meta' => 100,
            'verificados' => 25,
        ]);

        $resultado = (new AgregarCoberturaTerritorial)->handle(collect([$seccionA->id, $seccionB->id]));

        // lista_nominal total = 1500; capturados = 150; verificados = 75.
        $this->assertEqualsWithDelta(150 / 1500, $resultado['penetracion'], 0.0001);
        $this->assertEqualsWithDelta(75 / 1500, $resultado['movilizacion_verificada'], 0.0001);
    }

    public function test_sin_meta_ni_lista_nominal_devuelve_cero_en_vez_de_dividir_por_cero(): void
    {
        $seccion = Seccion::factory()->create(['municipio_id' => $this->tenant->municipio_id, 'lista_nominal' => 0]);

        CoberturaSeccion::factory()->create([
            'tenant_id' => $this->tenant->id,
            'seccion_id' => $seccion->id,
            'capturados' => 0,
            'meta' => 0,
            'verificados' => 0,
        ]);

        $resultado = (new AgregarCoberturaTerritorial)->handle(collect([$seccion->id]));

        $this->assertSame(0.0, $resultado['cobertura']);
        $this->assertSame(0.0, $resultado['penetracion']);
        $this->assertSame(0.0, $resultado['movilizacion_verificada']);
    }
}
