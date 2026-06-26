<?php

namespace Tests\Feature\MapaCobertura;

use App\Actions\Metas\DefinirMetaSeccion;
use App\Models\CoberturaSeccion;
use App\Models\Membership;
use App\Models\Municipio;
use App\Models\Seccion;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MapaCoberturaEndpointsTest extends TestCase
{
    use RefreshDatabase;

    private function tenantConMiembro(string $rol = 'coordinador'): array
    {
        $municipio = Municipio::factory()->create();
        $tenant = Tenant::factory()->create(['municipio_id' => $municipio->id]);
        $user = User::factory()->create();
        Membership::create(['tenant_id' => $tenant->id, 'user_id' => $user->id, 'rol' => $rol]);

        return [$tenant, $user, $municipio];
    }

    public function test_geojson_sin_metas_devuelve_features_en_cero(): void
    {
        [$tenant, $user, $municipio] = $this->tenantConMiembro();
        $seccion = Seccion::factory()->create(['municipio_id' => $municipio->id]);

        $response = $this->actingAs($user)->withSession(['tenant_id' => $tenant->id])
            ->getJson('/api/cobertura.geojson');

        $response->assertOk();
        $response->assertJsonPath('type', 'FeatureCollection');
        $feature = collect($response->json('features'))->firstWhere('properties.seccion_id', $seccion->id);
        $this->assertNotNull($feature);
        $this->assertSame(0, $feature['properties']['capturados']);
        $this->assertSame(0, $feature['properties']['meta']);
    }

    public function test_geojson_scoped_por_tenant(): void
    {
        [$tenantA, $userA, $municipio] = $this->tenantConMiembro();
        [$tenantB, $userB] = $this->tenantConMiembro();
        Tenant::query()->whereKey($tenantB->id)->update(['municipio_id' => $municipio->id]);

        $seccion = Seccion::factory()->create(['municipio_id' => $municipio->id]);

        TenantContext::set($tenantA);
        CoberturaSeccion::create(['seccion_id' => $seccion->id, 'capturados' => 42, 'meta' => 100, 'cobertura' => 0.42, 'penetracion' => 0]);

        $responseB = $this->actingAs($userB)->withSession(['tenant_id' => $tenantB->id])
            ->getJson('/api/cobertura.geojson');

        $feature = collect($responseB->json('features'))->firstWhere('properties.seccion_id', $seccion->id);
        $this->assertSame(0, $feature['properties']['capturados']);
    }

    public function test_resumen_seccion_sin_fila_devuelve_ceros(): void
    {
        [$tenant, $user, $municipio] = $this->tenantConMiembro();
        $seccion = Seccion::factory()->create(['municipio_id' => $municipio->id]);

        $response = $this->actingAs($user)->withSession(['tenant_id' => $tenant->id])
            ->getJson("/api/secciones/{$seccion->id}/resumen");

        $response->assertOk();
        $response->assertJson([
            'numero' => $seccion->numero,
            'capturados' => 0,
            'meta' => 0,
            'cobertura' => 0,
            'penetracion' => 0,
            'brigadistas_activos' => [],
            'ultimo_registro' => null,
        ]);
    }

    public function test_brigadista_no_puede_escribir_meta(): void
    {
        [$tenant, $user, $municipio] = $this->tenantConMiembro('brigadista');
        $seccion = Seccion::factory()->create(['municipio_id' => $municipio->id]);

        $response = $this->actingAs($user)->withSession(['tenant_id' => $tenant->id])
            ->putJson("/api/secciones/{$seccion->id}/meta", ['fuente_meta' => 'manual', 'meta_capturas' => 100]);

        $response->assertForbidden();
    }

    public function test_coordinador_puede_escribir_meta_manual(): void
    {
        [$tenant, $user, $municipio] = $this->tenantConMiembro('coordinador');
        $seccion = Seccion::factory()->create(['municipio_id' => $municipio->id]);

        $response = $this->actingAs($user)->withSession(['tenant_id' => $tenant->id])
            ->putJson("/api/secciones/{$seccion->id}/meta", ['fuente_meta' => 'manual', 'meta_capturas' => 100]);

        $response->assertOk();

        TenantContext::set($tenant);
        $this->assertSame(100, CoberturaSeccion::where('seccion_id', $seccion->id)->first()->meta);
    }

    public function test_coordinador_puede_escribir_meta_por_porcentaje(): void
    {
        [$tenant, $user, $municipio] = $this->tenantConMiembro('coordinador');
        $seccion = Seccion::factory()->create(['municipio_id' => $municipio->id, 'lista_nominal' => 1000]);

        $response = $this->actingAs($user)->withSession(['tenant_id' => $tenant->id])
            ->putJson("/api/secciones/{$seccion->id}/meta", ['fuente_meta' => 'lista_nominal_pct', 'pct_lista_nominal' => 20]);

        $response->assertOk();

        TenantContext::set($tenant);
        $this->assertSame(200, CoberturaSeccion::where('seccion_id', $seccion->id)->first()->meta);
    }

    public function test_pagina_metas_expone_secciones_con_su_meta_actual(): void
    {
        [$tenant, $user, $municipio] = $this->tenantConMiembro('coordinador');
        $seccion = Seccion::factory()->create(['municipio_id' => $municipio->id]);

        TenantContext::set($tenant);
        (new DefinirMetaSeccion)->handle($seccion, 'manual', metaCapturas: 80);

        $response = $this->actingAs($user)->withSession(['tenant_id' => $tenant->id])->get('/metas');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Metas')
            ->has('secciones', 1)
            ->where('secciones.0.numero', $seccion->numero)
            ->where('secciones.0.meta_capturas', 80)
            ->where('secciones.0.fuente_meta', 'manual'));
    }

    public function test_brigadista_no_puede_ver_la_pagina_metas(): void
    {
        [$tenant, $user] = $this->tenantConMiembro('brigadista');

        $response = $this->actingAs($user)->withSession(['tenant_id' => $tenant->id])->get('/metas');

        $response->assertForbidden();
    }
}
