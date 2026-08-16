<?php

namespace Tests\Feature\Zonas;

use App\Models\Seccion;
use App\Models\Tenant;
use App\Models\Zona;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Criterio 7 de F7: `territori:recalcular-zonas {tenant}` es idempotente.
 */
class RecalcularZonasCommandTest extends TestCase
{
    use RefreshDatabase;

    private function fijarGeom(string $tabla, int $id, float $x1, float $y1, float $x2, float $y2): void
    {
        $geojson = [
            'type' => 'Polygon',
            'coordinates' => [[[$x1, $y1], [$x1, $y2], [$x2, $y2], [$x2, $y1], [$x1, $y1]]],
        ];

        DB::statement(
            "update {$tabla} set geom = ST_Multi(ST_SetSRID(ST_GeomFromGeoJSON(?), 4326)) where id = ?",
            [json_encode($geojson), $id]
        );
    }

    public function test_recalcula_zona_seccion_para_todas_las_zonas_del_tenant(): void
    {
        $tenant = Tenant::factory()->create();
        TenantContext::set($tenant);

        $seccion = Seccion::factory()->create();
        $this->fijarGeom('secciones', $seccion->id, 0, 0, 1, 1);

        $zona = Zona::factory()->sinGeom()->create(['nombre' => 'Zona A']);
        $this->fijarGeom('zonas', $zona->id, 0, 0, 1, 1);

        $this->artisan('territori:recalcular-zonas', ['tenant' => $tenant->id])->assertSuccessful();

        TenantContext::set($tenant);
        $this->assertSame(1, DB::table('zona_seccion')->where('zona_id', $zona->id)->count());
    }

    public function test_es_idempotente(): void
    {
        $tenant = Tenant::factory()->create();
        TenantContext::set($tenant);

        $seccion = Seccion::factory()->create();
        $this->fijarGeom('secciones', $seccion->id, 0, 0, 1, 1);

        $zona = Zona::factory()->sinGeom()->create(['nombre' => 'Zona A']);
        $this->fijarGeom('zonas', $zona->id, 0, 0, 1, 1);

        $this->artisan('territori:recalcular-zonas', ['tenant' => $tenant->id])->assertSuccessful();
        TenantContext::set($tenant);
        $primero = DB::table('zona_seccion')->where('zona_id', $zona->id)->get()->map(fn ($f) => (array) $f)->all();

        $this->artisan('territori:recalcular-zonas', ['tenant' => $tenant->id])->assertSuccessful();
        TenantContext::set($tenant);
        $segundo = DB::table('zona_seccion')->where('zona_id', $zona->id)->get()->map(fn ($f) => (array) $f)->all();

        $this->assertSame(1, count($segundo));
        // Ignora calculado_en (timestamp de cada corrida), compara el resto.
        $limpiar = fn (array $filas) => array_map(function (array $fila) {
            unset($fila['calculado_en']);

            return $fila;
        }, $filas);

        $this->assertSame($limpiar($primero), $limpiar($segundo));
    }

    public function test_no_afecta_zonas_de_otro_tenant(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();

        TenantContext::set($tenantA);
        $seccion = Seccion::factory()->create();
        $this->fijarGeom('secciones', $seccion->id, 0, 0, 1, 1);
        $zonaA = Zona::factory()->sinGeom()->create(['nombre' => 'Zona A']);
        $this->fijarGeom('zonas', $zonaA->id, 0, 0, 1, 1);

        TenantContext::set($tenantB);
        $zonaB = Zona::factory()->sinGeom()->create(['nombre' => 'Zona B']);
        $this->fijarGeom('zonas', $zonaB->id, 0, 0, 1, 1);

        $this->artisan('territori:recalcular-zonas', ['tenant' => $tenantA->id])->assertSuccessful();

        TenantContext::set($tenantA);
        $this->assertSame(1, DB::table('zona_seccion')->where('zona_id', $zonaA->id)->count());

        TenantContext::set($tenantB);
        $this->assertSame(0, DB::table('zona_seccion')->where('zona_id', $zonaB->id)->count());
    }
}
