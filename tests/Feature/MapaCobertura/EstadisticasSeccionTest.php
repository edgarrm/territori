<?php

namespace Tests\Feature\MapaCobertura;

use App\Http\Controllers\MapaController;
use App\Models\EstadisticaSeccion;
use App\Models\Membership;
use App\Models\Municipio;
use App\Models\Seccion;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EstadisticasSeccionTest extends TestCase
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

    public function test_geojson_incluye_estadistica_electoral_y_demografica(): void
    {
        [$tenant, $user, $municipio] = $this->tenantConMiembro();
        $seccion = Seccion::factory()->create(['municipio_id' => $municipio->id]);
        EstadisticaSeccion::factory()->create([
            'seccion_id' => $seccion->id,
            'ganador_bloque' => 'Morena-PVEM',
            'margen_pp' => 10.34,
            'indice_oportunidad' => 88.35,
            'nivel_oportunidad' => 'Alta',
        ]);

        $response = $this->actingAs($user)->withSession(['tenant_id' => $tenant->id])
            ->getJson('/api/cobertura.geojson');

        $response->assertOk();
        $propiedades = collect($response->json('features'))
            ->firstWhere('properties.seccion_id', $seccion->id)['properties'];

        $this->assertSame('Morena-PVEM', $propiedades['ganador_bloque']);
        $this->assertSame(10.34, $propiedades['margen_pp']);
        $this->assertSame(88.35, $propiedades['indice_oportunidad']);
        $this->assertSame('Alta', $propiedades['nivel_oportunidad']);
    }

    public function test_geojson_sin_estadistica_devuelve_nulls(): void
    {
        [$tenant, $user, $municipio] = $this->tenantConMiembro();
        $seccion = Seccion::factory()->create(['municipio_id' => $municipio->id]);

        $response = $this->actingAs($user)->withSession(['tenant_id' => $tenant->id])
            ->getJson('/api/cobertura.geojson');

        $propiedades = collect($response->json('features'))
            ->firstWhere('properties.seccion_id', $seccion->id)['properties'];

        $this->assertNull($propiedades['ganador_bloque']);
        $this->assertNull($propiedades['margen_pp']);
        $this->assertNull($propiedades['indice_oportunidad']);
    }

    public function test_resumen_incluye_bloques_electoral_y_demografia(): void
    {
        [$tenant, $user, $municipio] = $this->tenantConMiembro();
        $seccion = Seccion::factory()->create(['municipio_id' => $municipio->id]);
        EstadisticaSeccion::factory()->create([
            'seccion_id' => $seccion->id,
            'ganador_bloque' => 'Fuerza y Corazón',
            'margen_pp' => 4.11,
            'nivel_oportunidad' => 'Alta',
            'recomendacion' => 'Prioridad alta con estrategia por edad',
        ]);

        $response = $this->actingAs($user)->withSession(['tenant_id' => $tenant->id])
            ->getJson("/api/secciones/{$seccion->id}/resumen");

        $response->assertOk();
        $response->assertJsonPath('electoral_2024.ganador_bloque', 'Fuerza y Corazón');
        $response->assertJsonPath('electoral_2024.margen_pp', 4.11);
        $response->assertJsonPath('demografia.nivel_oportunidad', 'Alta');
        $response->assertJsonPath('demografia.recomendacion', 'Prioridad alta con estrategia por edad');
        $this->assertIsArray($response->json('demografia.grupos_edad'));
    }

    public function test_resumen_sin_estadistica_devuelve_bloques_null(): void
    {
        [$tenant, $user, $municipio] = $this->tenantConMiembro();
        $seccion = Seccion::factory()->create(['municipio_id' => $municipio->id]);

        $response = $this->actingAs($user)->withSession(['tenant_id' => $tenant->id])
            ->getJson("/api/secciones/{$seccion->id}/resumen");

        $response->assertOk();
        $this->assertNull($response->json('electoral_2024'));
        $this->assertNull($response->json('demografia'));
    }

    public function test_resumen_solo_electoral_devuelve_demografia_null(): void
    {
        [$tenant, $user, $municipio] = $this->tenantConMiembro();
        $seccion = Seccion::factory()->create(['municipio_id' => $municipio->id]);
        EstadisticaSeccion::factory()->sinDemografia()->create(['seccion_id' => $seccion->id]);

        $response = $this->actingAs($user)->withSession(['tenant_id' => $tenant->id])
            ->getJson("/api/secciones/{$seccion->id}/resumen");

        $response->assertOk();
        $this->assertNotNull($response->json('electoral_2024'));
        $this->assertNull($response->json('demografia'));
    }

    public function test_prioridades_expone_secciones_con_indice_calculado(): void
    {
        [$tenant, $user, $municipio] = $this->tenantConMiembro();
        $seccion = Seccion::factory()->create(['municipio_id' => $municipio->id]);
        EstadisticaSeccion::factory()->create([
            'seccion_id' => $seccion->id,
            'margen_pp' => 5.0,
            'indice_oportunidad' => 90.0,
        ]);

        $response = $this->actingAs($user)->withSession(['tenant_id' => $tenant->id])->get('/prioridades');

        $response->assertOk();
        // 0.4·(100−5·4) + 0.4·90 + 0.2·100 = 32 + 36 + 20 = 88.
        $response->assertInertia(fn ($page) => $page
            ->component('Prioridades')
            ->has('secciones', 1)
            ->where('secciones.0.numero', $seccion->numero)
            ->where('secciones.0.prioridad', fn ($v) => (float) $v === 88.0));
    }

    public function test_prioridades_sin_estadistica_devuelve_prioridad_null(): void
    {
        [$tenant, $user, $municipio] = $this->tenantConMiembro();
        Seccion::factory()->create(['municipio_id' => $municipio->id]);

        $response = $this->actingAs($user)->withSession(['tenant_id' => $tenant->id])->get('/prioridades');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Prioridades')
            ->where('secciones.0.prioridad', null));
    }

    public function test_prioridades_rechaza_al_rol_enlace(): void
    {
        [$tenant, $user] = $this->tenantConMiembro('enlace');

        $response = $this->actingAs($user)->withSession(['tenant_id' => $tenant->id])->get('/prioridades');

        $response->assertForbidden();
    }

    public function test_calculo_de_prioridad_pondera_margen_oportunidad_y_cobertura(): void
    {
        // Sección muy cerrada, alta oportunidad y sin cobertura: prioridad máxima.
        $this->assertSame(100.0, MapaController::calcularPrioridad(0.0, 100.0, 0.0));

        // Margen enorme se topa en 25pp: la competitividad aporta 0.
        $this->assertSame(0.0, MapaController::calcularPrioridad(40.0, 0.0, 1.0));

        // Sin dato de margen, esa componente aporta 0.
        $this->assertSame(60.0, MapaController::calcularPrioridad(null, 100.0, 0.0));

        // Sin ningún dato estadístico no hay prioridad.
        $this->assertNull(MapaController::calcularPrioridad(null, null, 0.5));
    }
}
