<?php

namespace Tests\Feature\MapaCobertura;

use App\Actions\Metas\DefinirMetaSeccion;
use App\Models\CoberturaSeccion;
use App\Models\MetaSeccion;
use App\Models\Municipio;
use App\Models\Seccion;
use App\Models\Tenant;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class DefinirMetaSeccionTest extends TestCase
{
    use RefreshDatabase;

    private function tenantConSeccion(?int $listaNominal = null): array
    {
        $municipio = Municipio::factory()->create();
        $seccion = Seccion::factory()->create(['municipio_id' => $municipio->id, 'lista_nominal' => $listaNominal]);
        $tenant = Tenant::factory()->create(['municipio_id' => $municipio->id]);

        TenantContext::set($tenant);

        return [$tenant, $seccion];
    }

    public function test_meta_manual_fija_el_valor_exacto(): void
    {
        [, $seccion] = $this->tenantConSeccion();

        (new DefinirMetaSeccion)->handle($seccion, 'manual', metaCapturas: 100);

        $meta = MetaSeccion::where('seccion_id', $seccion->id)->first();
        $this->assertSame(100, $meta->meta_capturas);
        $this->assertSame('manual', $meta->fuente_meta);
    }

    public function test_meta_por_porcentaje_de_lista_nominal_se_redondea(): void
    {
        [, $seccion] = $this->tenantConSeccion(listaNominal: 2000);

        (new DefinirMetaSeccion)->handle($seccion, 'lista_nominal_pct', pct: 30);

        $meta = MetaSeccion::where('seccion_id', $seccion->id)->first();
        $this->assertSame(600, $meta->meta_capturas);
        $this->assertSame('lista_nominal_pct', $meta->fuente_meta);
        $this->assertEquals(30, $meta->pct_lista_nominal);
    }

    public function test_meta_por_porcentaje_sin_lista_nominal_resulta_en_cero(): void
    {
        [, $seccion] = $this->tenantConSeccion(listaNominal: null);

        (new DefinirMetaSeccion)->handle($seccion, 'lista_nominal_pct', pct: 30);

        $meta = MetaSeccion::where('seccion_id', $seccion->id)->first();
        $this->assertSame(0, $meta->meta_capturas);
    }

    public function test_definir_meta_es_upsert_no_duplica_fila(): void
    {
        [, $seccion] = $this->tenantConSeccion();

        (new DefinirMetaSeccion)->handle($seccion, 'manual', metaCapturas: 100);
        (new DefinirMetaSeccion)->handle($seccion, 'manual', metaCapturas: 200);

        $this->assertSame(1, MetaSeccion::where('seccion_id', $seccion->id)->count());
        $this->assertSame(200, MetaSeccion::where('seccion_id', $seccion->id)->first()->meta_capturas);
    }

    public function test_definir_meta_refresca_cobertura_seccion_existente(): void
    {
        [$tenant, $seccion] = $this->tenantConSeccion();

        CoberturaSeccion::create([
            'seccion_id' => $seccion->id,
            'capturados' => 20,
            'meta' => 0,
            'cobertura' => 0,
            'penetracion' => 0,
        ]);

        (new DefinirMetaSeccion)->handle($seccion, 'manual', metaCapturas: 100);

        $cobertura = CoberturaSeccion::where('tenant_id', $tenant->id)->where('seccion_id', $seccion->id)->first();
        $this->assertSame(100, $cobertura->meta);
        $this->assertEquals(0.2, $cobertura->cobertura);
    }

    public function test_definir_meta_crea_cobertura_seccion_si_no_existia(): void
    {
        [$tenant, $seccion] = $this->tenantConSeccion();

        (new DefinirMetaSeccion)->handle($seccion, 'manual', metaCapturas: 50);

        $cobertura = CoberturaSeccion::where('tenant_id', $tenant->id)->where('seccion_id', $seccion->id)->first();
        $this->assertNotNull($cobertura);
        $this->assertSame(0, $cobertura->capturados);
        $this->assertSame(50, $cobertura->meta);
        $this->assertEquals(0, $cobertura->cobertura);
    }

    public function test_meta_cero_no_produce_division_por_cero(): void
    {
        [, $seccion] = $this->tenantConSeccion();

        (new DefinirMetaSeccion)->handle($seccion, 'manual', metaCapturas: 0);

        $cobertura = CoberturaSeccion::where('seccion_id', $seccion->id)->first();
        $this->assertEquals(0, $cobertura->cobertura);
    }

    public function test_refrescar_cobertura_no_afecta_la_fila_de_otro_tenant(): void
    {
        [$tenantA, $seccionA] = $this->tenantConSeccion();
        TenantContext::clear();
        [$tenantB, $seccionB] = $this->tenantConSeccion();

        TenantContext::set($tenantA);
        CoberturaSeccion::create(['seccion_id' => $seccionA->id, 'capturados' => 5, 'meta' => 0, 'cobertura' => 0, 'penetracion' => 0]);

        TenantContext::set($tenantB);
        CoberturaSeccion::create(['seccion_id' => $seccionB->id, 'capturados' => 9, 'meta' => 0, 'cobertura' => 0, 'penetracion' => 0]);

        TenantContext::set($tenantA);
        (new DefinirMetaSeccion)->handle($seccionA, 'manual', metaCapturas: 100);

        TenantContext::set($tenantB);
        $coberturaB = CoberturaSeccion::where('seccion_id', $seccionB->id)->first();
        $this->assertSame(0, $coberturaB->meta);
        $this->assertSame(9, $coberturaB->capturados);

        TenantContext::set($tenantA);
        $coberturaA = CoberturaSeccion::where('seccion_id', $seccionA->id)->first();
        $this->assertSame(100, $coberturaA->meta);
    }

    public function test_no_define_meta_en_seccion_de_otro_municipio(): void
    {
        [$tenant] = $this->tenantConSeccion();
        $otroMunicipio = Municipio::factory()->create();
        $seccionAjena = Seccion::factory()->create(['municipio_id' => $otroMunicipio->id]);

        TenantContext::set($tenant);

        $this->expectException(ValidationException::class);
        (new DefinirMetaSeccion)->handle($seccionAjena, 'manual', metaCapturas: 100);
    }

    public function test_coberturaseccion_save_de_instancia_existente_lanza_excepcion(): void
    {
        [, $seccion] = $this->tenantConSeccion();
        $cobertura = CoberturaSeccion::create(['seccion_id' => $seccion->id, 'capturados' => 1, 'meta' => 1, 'cobertura' => 1, 'penetracion' => 1]);

        $encontrada = CoberturaSeccion::where('seccion_id', $seccion->id)->first();
        $encontrada->meta = 50;

        $this->expectException(\LogicException::class);
        $encontrada->save();
    }
}
