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

class AislamientoMetasCoberturaTest extends TestCase
{
    use RefreshDatabase;

    public function test_metas_seccion_no_se_mezcla_entre_tenants_de_la_misma_seccion_fisica(): void
    {
        $municipio = Municipio::factory()->create();
        $seccion = Seccion::factory()->create(['municipio_id' => $municipio->id]);

        $tenantA = Tenant::factory()->create(['municipio_id' => $municipio->id]);
        $tenantB = Tenant::factory()->create(['municipio_id' => $municipio->id]);

        TenantContext::set($tenantA);
        MetaSeccion::create(['seccion_id' => $seccion->id, 'meta_capturas' => 100, 'fuente_meta' => 'manual']);

        TenantContext::set($tenantB);
        MetaSeccion::create(['seccion_id' => $seccion->id, 'meta_capturas' => 999, 'fuente_meta' => 'manual']);

        TenantContext::set($tenantA);
        $this->assertSame([100], MetaSeccion::pluck('meta_capturas')->all());

        TenantContext::set($tenantB);
        $this->assertSame([999], MetaSeccion::pluck('meta_capturas')->all());
    }

    public function test_cobertura_seccion_no_se_mezcla_entre_tenants_de_la_misma_seccion_fisica(): void
    {
        $municipio = Municipio::factory()->create();
        $seccion = Seccion::factory()->create(['municipio_id' => $municipio->id]);

        $tenantA = Tenant::factory()->create(['municipio_id' => $municipio->id]);
        $tenantB = Tenant::factory()->create(['municipio_id' => $municipio->id]);

        TenantContext::set($tenantA);
        CoberturaSeccion::create(['seccion_id' => $seccion->id, 'capturados' => 10, 'meta' => 100, 'cobertura' => 0.1, 'penetracion' => 0]);

        TenantContext::set($tenantB);
        CoberturaSeccion::create(['seccion_id' => $seccion->id, 'capturados' => 50, 'meta' => 100, 'cobertura' => 0.5, 'penetracion' => 0]);

        TenantContext::set($tenantA);
        $this->assertSame([10], CoberturaSeccion::pluck('capturados')->all());

        TenantContext::set($tenantB);
        $this->assertSame([50], CoberturaSeccion::pluck('capturados')->all());
    }

    public function test_sin_tenant_activo_metas_y_cobertura_no_devuelven_nada(): void
    {
        $municipio = Municipio::factory()->create();
        $seccion = Seccion::factory()->create(['municipio_id' => $municipio->id]);
        $tenant = Tenant::factory()->create(['municipio_id' => $municipio->id]);

        TenantContext::set($tenant);
        MetaSeccion::create(['seccion_id' => $seccion->id, 'meta_capturas' => 100, 'fuente_meta' => 'manual']);
        CoberturaSeccion::create(['seccion_id' => $seccion->id, 'capturados' => 10, 'meta' => 100, 'cobertura' => 0.1, 'penetracion' => 0]);

        TenantContext::clear();

        $this->assertSame(0, MetaSeccion::count());
        $this->assertSame(0, CoberturaSeccion::count());
    }
}
