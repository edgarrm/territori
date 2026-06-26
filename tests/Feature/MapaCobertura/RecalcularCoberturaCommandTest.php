<?php

namespace Tests\Feature\MapaCobertura;

use App\Models\CoberturaSeccion;
use App\Models\MetaSeccion;
use App\Models\Municipio;
use App\Models\Seccion;
use App\Models\Tenant;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RecalcularCoberturaCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_crea_cobertura_en_cero_para_todas_las_secciones_del_municipio_sin_metas(): void
    {
        $municipio = Municipio::factory()->create();
        $seccionA = Seccion::factory()->create(['municipio_id' => $municipio->id]);
        $seccionB = Seccion::factory()->create(['municipio_id' => $municipio->id]);
        $tenant = Tenant::factory()->create(['municipio_id' => $municipio->id]);

        $this->artisan('territori:recalcular-cobertura', ['tenant' => $tenant->id])->assertSuccessful();

        TenantContext::set($tenant);
        $this->assertSame(2, CoberturaSeccion::count());
        $coberturaA = CoberturaSeccion::where('seccion_id', $seccionA->id)->first();
        $this->assertSame(0, $coberturaA->capturados);
        $this->assertSame(0, $coberturaA->meta);
        $this->assertEquals(0, $coberturaA->cobertura);
    }

    public function test_usa_la_meta_definida_para_calcular_cobertura(): void
    {
        $municipio = Municipio::factory()->create();
        $seccion = Seccion::factory()->create(['municipio_id' => $municipio->id]);
        $tenant = Tenant::factory()->create(['municipio_id' => $municipio->id]);

        TenantContext::set($tenant);
        MetaSeccion::create(['seccion_id' => $seccion->id, 'meta_capturas' => 50, 'fuente_meta' => 'manual']);

        $this->artisan('territori:recalcular-cobertura', ['tenant' => $tenant->id])->assertSuccessful();

        $cobertura = CoberturaSeccion::where('seccion_id', $seccion->id)->first();
        $this->assertSame(50, $cobertura->meta);
    }

    public function test_es_idempotente(): void
    {
        $municipio = Municipio::factory()->create();
        $seccion = Seccion::factory()->create(['municipio_id' => $municipio->id]);
        $tenant = Tenant::factory()->create(['municipio_id' => $municipio->id]);

        $this->artisan('territori:recalcular-cobertura', ['tenant' => $tenant->id])->assertSuccessful();
        TenantContext::set($tenant);
        $primero = CoberturaSeccion::where('seccion_id', $seccion->id)->first()->toArray();

        $this->artisan('territori:recalcular-cobertura', ['tenant' => $tenant->id])->assertSuccessful();
        $this->assertSame(1, CoberturaSeccion::where('seccion_id', $seccion->id)->count());
        $segundo = CoberturaSeccion::where('seccion_id', $seccion->id)->first()->toArray();

        unset($primero['actualizado_en'], $segundo['actualizado_en']);
        $this->assertSame($primero, $segundo);
    }

    public function test_no_afecta_cobertura_de_otro_tenant(): void
    {
        $municipio = Municipio::factory()->create();
        $seccion = Seccion::factory()->create(['municipio_id' => $municipio->id]);
        $tenantA = Tenant::factory()->create(['municipio_id' => $municipio->id]);
        $tenantB = Tenant::factory()->create(['municipio_id' => $municipio->id]);

        TenantContext::set($tenantB);
        MetaSeccion::create(['seccion_id' => $seccion->id, 'meta_capturas' => 777, 'fuente_meta' => 'manual']);

        $this->artisan('territori:recalcular-cobertura', ['tenant' => $tenantA->id])->assertSuccessful();

        TenantContext::set($tenantA);
        $coberturaA = CoberturaSeccion::where('seccion_id', $seccion->id)->first();
        $this->assertSame(0, $coberturaA->meta);

        TenantContext::set($tenantB);
        $this->assertSame(0, CoberturaSeccion::count());
    }
}
