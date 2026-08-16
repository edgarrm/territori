<?php

namespace Tests\Feature\Zonas;

use App\Models\Membership;
use App\Models\Municipio;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Zona;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use MatanYadaev\EloquentSpatial\Enums\Srid;
use MatanYadaev\EloquentSpatial\Objects\Point;
use Tests\TestCase;

class ZonasTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{0: Tenant, 1: User, 2: Membership}
     */
    private function setupCampana(string $rol = 'coordinador'): array
    {
        // Zonas.vue es una página Inertia; sin build no hay manifest de Vite.
        $this->withoutVite();

        $tenant = Tenant::factory()->create();
        $user = User::factory()->create();
        $membership = Membership::factory()->create([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'rol' => $rol,
        ]);
        TenantContext::set($tenant);

        return [$tenant, $user, $membership];
    }

    /**
     * @return array<string, mixed>
     */
    private function geometriaSimple(): array
    {
        return [
            'type' => 'Polygon',
            'coordinates' => [[[0, 0], [0, 1], [1, 1], [1, 0], [0, 0]]],
        ];
    }

    /**
     * Geometría autointersectada (bowtie): ST_IsValid falso.
     *
     * @return array<string, mixed>
     */
    private function geometriaInvalida(): array
    {
        return [
            'type' => 'Polygon',
            'coordinates' => [[[0, 0], [1, 1], [1, 0], [0, 1], [0, 0]]],
        ];
    }

    // --- 1. Crear zona válida: fuente=dibujada, activa=true ---
    public function test_coordinador_crea_zona_con_geojson_valido(): void
    {
        [$tenant, $user] = $this->setupCampana('coordinador');

        $this->actingAs($user)->withSession(['tenant_id' => $tenant->id])
            ->post('/zonas', [
                'nombre' => 'Colonia X',
                'tipo' => 'colonia',
                'geom' => $this->geometriaSimple(),
            ])->assertSessionHasNoErrors();

        TenantContext::set($tenant);
        $zona = Zona::query()->where('nombre', 'Colonia X')->firstOrFail();

        $this->assertSame('dibujada', $zona->fuente);
        $this->assertTrue($zona->activa);
        $this->assertSame('colonia', $zona->tipo);
        $this->assertNotNull($zona->geom);

        $srid = DB::selectOne('select ST_SRID(geom) as srid from zonas where id = ?', [$zona->id]);
        $this->assertSame(4326, $srid->srid);
    }

    // --- 2. Geometría inválida o SRID incorrecto: 422, nada persistido ---
    public function test_geometria_invalida_falla_sin_persistir(): void
    {
        [$tenant, $user] = $this->setupCampana('coordinador');

        $this->actingAs($user)->withSession(['tenant_id' => $tenant->id])
            ->post('/zonas', [
                'nombre' => 'Zona inválida',
                'tipo' => 'colonia',
                'geom' => $this->geometriaInvalida(),
            ])->assertSessionHasErrors('geom');

        TenantContext::set($tenant);
        $this->assertSame(0, Zona::query()->count());
    }

    public function test_srid_incorrecto_falla_sin_persistir(): void
    {
        [$tenant, $user] = $this->setupCampana('coordinador');

        $geom = $this->geometriaSimple();
        $geom['crs'] = ['type' => 'name', 'properties' => ['name' => 'urn:ogc:def:crs:EPSG::3857']];

        $this->actingAs($user)->withSession(['tenant_id' => $tenant->id])
            ->post('/zonas', [
                'nombre' => 'Zona SRID malo',
                'tipo' => 'colonia',
                'geom' => $geom,
            ])->assertSessionHasErrors('geom');

        TenantContext::set($tenant);
        $this->assertSame(0, Zona::query()->count());
    }

    // --- 3. Unicidad (tenant_id, clave) ---
    public function test_dos_zonas_del_mismo_tenant_no_pueden_compartir_clave(): void
    {
        [$tenant, $user] = $this->setupCampana('coordinador');
        TenantContext::set($tenant);
        Zona::factory()->create(['clave' => 'ZONA-1']);

        $this->actingAs($user)->withSession(['tenant_id' => $tenant->id])
            ->post('/zonas', [
                'nombre' => 'Otra zona',
                'tipo' => 'colonia',
                'clave' => 'ZONA-1',
            ])->assertSessionHasErrors('clave');

        TenantContext::set($tenant);
        $this->assertSame(1, Zona::query()->where('clave', 'ZONA-1')->count());
    }

    public function test_dos_zonas_de_distinto_tenant_si_pueden_compartir_clave(): void
    {
        [$tenantUno, $userUno] = $this->setupCampana('coordinador');
        TenantContext::set($tenantUno);
        Zona::factory()->create(['clave' => 'ZONA-1']);

        [$tenantDos, $userDos] = $this->setupCampana('coordinador');

        $this->actingAs($userDos)->withSession(['tenant_id' => $tenantDos->id])
            ->post('/zonas', [
                'nombre' => 'Zona del otro tenant',
                'tipo' => 'colonia',
                'clave' => 'ZONA-1',
            ])->assertSessionHasNoErrors();

        TenantContext::set($tenantDos);
        $this->assertSame(1, Zona::query()->where('clave', 'ZONA-1')->count());
    }

    // --- 6. Geometría fuera del municipio del tenant: permitido ---
    public function test_zona_fuera_del_municipio_del_tenant_se_permite(): void
    {
        $municipioMazatlan = Municipio::factory()->create();
        $tenant = Tenant::factory()->create(['municipio_id' => $municipioMazatlan->id]);
        $user = User::factory()->create();
        Membership::factory()->create(['tenant_id' => $tenant->id, 'user_id' => $user->id, 'rol' => 'coordinador']);
        TenantContext::set($tenant);
        $this->withoutVite();

        // Geometría muy lejos de Sinaloa (otro continente/país): coordenadas
        // grandes, válidas en 4326, sin relación con el municipio del tenant.
        $geomLejana = [
            'type' => 'Polygon',
            'coordinates' => [[[100, 40], [100, 41], [101, 41], [101, 40], [100, 40]]],
        ];

        $this->actingAs($user)->withSession(['tenant_id' => $tenant->id])
            ->post('/zonas', [
                'nombre' => 'Zona internacional',
                'tipo' => 'pais',
                'geom' => $geomLejana,
            ])->assertSessionHasNoErrors();

        TenantContext::set($tenant);
        $zona = Zona::query()->where('nombre', 'Zona internacional')->firstOrFail();
        $this->assertNotNull($zona->geom);

        // Punto (100.5, 40.5) cae dentro del polígono, lejos de Mazatlán.
        $puntoLejano = new Point(40.5, 100.5, Srid::WGS84);
        $contiene = Zona::whereContains('geom', $puntoLejano)->whereKey($zona->id)->exists();
        $this->assertTrue($contiene);
    }

    // --- 7. GET /api/zonas.geojson: FeatureCollection con geometría simplificada ---
    public function test_geojson_devuelve_feature_collection(): void
    {
        [$tenant, $user] = $this->setupCampana('coordinador');
        TenantContext::set($tenant);
        Zona::factory()->create(['nombre' => 'Zona visible', 'activa' => true]);
        Zona::factory()->create(['nombre' => 'Zona inactiva', 'activa' => false]);
        Zona::factory()->sinGeom()->create(['nombre' => 'Zona sin geom', 'activa' => true]);

        $response = $this->actingAs($user)->withSession(['tenant_id' => $tenant->id])
            ->getJson('/api/zonas.geojson');

        $response->assertOk();
        $response->assertJsonPath('type', 'FeatureCollection');
        $response->assertJsonCount(1, 'features');
        $response->assertJsonPath('features.0.type', 'Feature');
        $response->assertJsonPath('features.0.properties.nombre', 'Zona visible');
        $this->assertNotNull($response->json('features.0.geometry'));
    }

    public function test_geojson_scoped_por_tenant(): void
    {
        [$tenantUno, $userUno] = $this->setupCampana('coordinador');
        TenantContext::set($tenantUno);
        Zona::factory()->create(['nombre' => 'Zona de tenant uno']);

        [$tenantDos, $userDos] = $this->setupCampana('coordinador');
        TenantContext::set($tenantDos);
        Zona::factory()->create(['nombre' => 'Zona de tenant dos']);

        $response = $this->actingAs($userDos)->withSession(['tenant_id' => $tenantDos->id])
            ->getJson('/api/zonas.geojson');

        $response->assertJsonCount(1, 'features');
        $response->assertJsonPath('features.0.properties.nombre', 'Zona de tenant dos');
    }

    // --- 8. Escritura restringida a coordinador/admin ---
    public function test_brigadista_no_puede_crear_zona(): void
    {
        [$tenant, $user] = $this->setupCampana('brigadista');

        $this->actingAs($user)->withSession(['tenant_id' => $tenant->id])
            ->post('/zonas', [
                'nombre' => 'Zona prohibida',
                'tipo' => 'colonia',
            ])->assertForbidden();

        TenantContext::set($tenant);
        $this->assertSame(0, Zona::query()->count());
    }

    public function test_brigadista_si_puede_leer_zonas(): void
    {
        [$tenant, $user] = $this->setupCampana('brigadista');
        TenantContext::set($tenant);
        Zona::factory()->create();

        $this->actingAs($user)->withSession(['tenant_id' => $tenant->id])
            ->get('/zonas')
            ->assertOk();

        $this->actingAs($user)->withSession(['tenant_id' => $tenant->id])
            ->getJson('/api/zonas.geojson')
            ->assertOk();
    }

    // --- 9. Zona tipo=mundial sin geom: permitido ---
    public function test_zona_mundial_sin_geom_se_permite(): void
    {
        [$tenant, $user] = $this->setupCampana('coordinador');

        $this->actingAs($user)->withSession(['tenant_id' => $tenant->id])
            ->post('/zonas', [
                'nombre' => 'Mundial',
                'tipo' => 'mundial',
            ])->assertSessionHasNoErrors();

        TenantContext::set($tenant);
        $zona = Zona::query()->where('nombre', 'Mundial')->firstOrFail();
        $this->assertNull($zona->geom);
        $this->assertSame('mundial', $zona->tipo);
    }

    // --- Extra: index Inertia ---
    public function test_index_renderiza_zonas_del_tenant(): void
    {
        [$tenant, $user] = $this->setupCampana('coordinador');
        TenantContext::set($tenant);
        Zona::factory()->create(['nombre' => 'Zona uno']);

        $this->actingAs($user)->withSession(['tenant_id' => $tenant->id])
            ->get('/zonas')
            ->assertInertia(fn ($page) => $page
                ->component('Zonas')
                ->has('zonas', 1)
            );
    }

    // --- Extra: actualizar y borrar ---
    public function test_coordinador_actualiza_zona(): void
    {
        [$tenant, $user] = $this->setupCampana('coordinador');
        TenantContext::set($tenant);
        $zona = Zona::factory()->create(['nombre' => 'Original', 'activa' => true]);

        $this->actingAs($user)->withSession(['tenant_id' => $tenant->id])
            ->put("/api/zonas/{$zona->id}", ['activa' => false])
            ->assertSessionHasNoErrors();

        TenantContext::set($tenant);
        $this->assertFalse($zona->fresh()->activa);
    }

    public function test_coordinador_elimina_zona(): void
    {
        [$tenant, $user] = $this->setupCampana('coordinador');
        TenantContext::set($tenant);
        $zona = Zona::factory()->create();

        $this->actingAs($user)->withSession(['tenant_id' => $tenant->id])
            ->delete("/api/zonas/{$zona->id}")
            ->assertSessionHasNoErrors();

        TenantContext::set($tenant);
        $this->assertSame(0, Zona::query()->whereKey($zona->id)->count());
    }

    // --- Extra: jerarquía padre/hija ---
    public function test_zona_hija_referencia_a_su_padre(): void
    {
        [$tenant, $user] = $this->setupCampana('coordinador');
        TenantContext::set($tenant);
        $padre = Zona::factory()->create(['nombre' => 'País', 'tipo' => 'pais', 'clave' => 'PAIS1']);

        $this->actingAs($user)->withSession(['tenant_id' => $tenant->id])
            ->post('/zonas', [
                'nombre' => 'Estado hijo',
                'tipo' => 'estado',
                'zona_padre_id' => $padre->id,
            ])->assertSessionHasNoErrors();

        TenantContext::set($tenant);
        $hija = Zona::query()->where('nombre', 'Estado hijo')->firstOrFail();
        $this->assertSame($padre->id, $hija->zona_padre_id);
        $this->assertTrue($padre->hijas()->whereKey($hija->id)->exists());
    }
}
