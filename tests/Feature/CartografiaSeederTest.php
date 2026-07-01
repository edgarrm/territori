<?php

namespace Tests\Feature;

use App\Models\Municipio;
use App\Models\Seccion;
use Database\Seeders\CartografiaSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use MatanYadaev\EloquentSpatial\Enums\Srid;
use MatanYadaev\EloquentSpatial\Objects\Point;
use Tests\TestCase;

class CartografiaSeederTest extends TestCase
{
    use RefreshDatabase;

    private string $fixturesDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fixturesDir = base_path('tests/Fixtures/cartografia/99-test');
    }

    public function test_habilita_postgis(): void
    {
        $extensionExiste = DB::selectOne(
            "select exists (select 1 from pg_extension where extname = 'postgis') as existe"
        );

        $this->assertTrue((bool) $extensionExiste->existe);
    }

    public function test_seeder_carga_entidad(): void
    {
        (new CartografiaSeeder)->run($this->fixturesDir);

        $entidad = DB::table('entidades')->where('clave', 99)->first();

        $this->assertNotNull($entidad);
        $this->assertSame('ESTADO DE PRUEBA', $entidad->nombre);

        $srid = DB::selectOne('select ST_SRID(geom) as srid from entidades where id = ?', [$entidad->id]);
        $this->assertSame(4326, $srid->srid);

        $esVacia = DB::selectOne('select ST_IsEmpty(geom) as vacia from entidades where id = ?', [$entidad->id]);
        $this->assertFalse((bool) $esVacia->vacia);
    }

    public function test_seeder_carga_municipios(): void
    {
        (new CartografiaSeeder)->run($this->fixturesDir);

        $this->assertSame(2, Municipio::count());

        $municipioDoce = Municipio::query()->where('clave', 12)->first();
        $this->assertNotNull($municipioDoce);
        $this->assertSame('MUNICIPIO DOCE', $municipioDoce->nombre);

        $srid = DB::selectOne('select ST_SRID(geom) as srid from municipios where id = ?', [$municipioDoce->id]);
        $this->assertSame(4326, $srid->srid);
    }

    public function test_municipios_unique_por_entidad_y_clave(): void
    {
        (new CartografiaSeeder)->run($this->fixturesDir);
        (new CartografiaSeeder)->run($this->fixturesDir);

        $this->assertSame(2, Municipio::count());
    }

    public function test_seeder_carga_secciones_del_fixture(): void
    {
        (new CartografiaSeeder)->run($this->fixturesDir);

        $municipioDoce = Municipio::query()->where('clave', 12)->first();
        $municipioUno = Municipio::query()->where('clave', 1)->first();

        $this->assertSame(3, Seccion::query()->where('municipio_id', $municipioDoce->id)->count());
        $this->assertSame(1, Seccion::query()->where('municipio_id', $municipioUno->id)->count());

        $seccion = Seccion::query()->where('municipio_id', $municipioDoce->id)->where('numero', 3)->first();
        $this->assertNotNull($seccion);
        $this->assertSame(2, $seccion->tipo);
        $this->assertSame(2, $seccion->distrito_federal);
        $this->assertSame(2, $seccion->distrito_local);
    }

    public function test_seeder_carga_lista_nominal_del_csv(): void
    {
        (new CartografiaSeeder)->run($this->fixturesDir);

        $municipioDoce = Municipio::query()->where('clave', 12)->first();
        $municipioUno = Municipio::query()->where('clave', 1)->first();

        // Encabezado en mayúsculas y "1,000" con separador de miles.
        $this->assertSame(
            1000,
            Seccion::query()->where('municipio_id', $municipioDoce->id)->where('numero', 1)->value('lista_nominal'),
        );
        $this->assertSame(
            1500,
            Seccion::query()->where('municipio_id', $municipioDoce->id)->where('numero', 3)->value('lista_nominal'),
        );

        // La sección 1 del municipio 1 no está en el CSV: queda sin lista nominal.
        $this->assertNull(
            Seccion::query()->where('municipio_id', $municipioUno->id)->where('numero', 1)->value('lista_nominal'),
        );
    }

    public function test_geom_srid_4326(): void
    {
        (new CartografiaSeeder)->run($this->fixturesDir);

        $seccion = Seccion::query()->where('numero', 1)->first();

        $srid = DB::selectOne('select ST_SRID(geom) as srid from secciones where id = ?', [$seccion->id]);
        $this->assertSame(4326, $srid->srid);
    }

    public function test_st_contains_punto_conocido(): void
    {
        (new CartografiaSeeder)->run($this->fixturesDir);

        $municipioDoce = Municipio::query()->where('clave', 12)->first();
        $puntoDentroDeSeccionUno = new Point(0.5, 0.5, Srid::WGS84);

        $seccion = Seccion::query()
            ->where('municipio_id', $municipioDoce->id)
            ->whereContains('geom', $puntoDentroDeSeccionUno)
            ->first();

        $this->assertNotNull($seccion);
        $this->assertSame(1, $seccion->numero);
    }

    public function test_polygon_se_guarda_como_multipolygon(): void
    {
        (new CartografiaSeeder)->run($this->fixturesDir);

        $seccion = Seccion::query()->where('numero', 1)->first();

        $tipoGeometria = DB::selectOne('select GeometryType(geom) as tipo from secciones where id = ?', [$seccion->id]);
        $this->assertSame('MULTIPOLYGON', $tipoGeometria->tipo);
    }

    public function test_idempotencia(): void
    {
        (new CartografiaSeeder)->run($this->fixturesDir);
        $conteoInicial = [
            'entidades' => DB::table('entidades')->count(),
            'municipios' => DB::table('municipios')->count(),
            'secciones' => DB::table('secciones')->count(),
        ];

        (new CartografiaSeeder)->run($this->fixturesDir);
        $conteoFinal = [
            'entidades' => DB::table('entidades')->count(),
            'municipios' => DB::table('municipios')->count(),
            'secciones' => DB::table('secciones')->count(),
        ];

        $this->assertSame($conteoInicial, $conteoFinal);
    }

    /**
     * @group slow
     */
    public function test_seeder_carga_mazatlan_con_dato_real(): void
    {
        (new CartografiaSeeder)->run(base_path('database/seeders/data/25-sinaloa'));

        $entidad = DB::table('entidades')->where('clave', 25)->first();
        $this->assertNotNull($entidad);
        $this->assertSame('SINALOA', $entidad->nombre);

        $this->assertSame(20, Municipio::count());

        $mazatlan = Municipio::query()->where('clave', 12)->first();
        $this->assertNotNull($mazatlan);
        $this->assertSame(530, Seccion::query()->where('municipio_id', $mazatlan->id)->count());

        $seccion = Seccion::query()->where('municipio_id', $mazatlan->id)->where('numero', 2756)->first();
        $this->assertNotNull($seccion);
        $this->assertSame(2, $seccion->tipo);
        $this->assertSame(1, $seccion->distrito_federal);
        $this->assertSame(22, $seccion->distrito_local);

        $puntoDentroDeLaSeccion2756 = new Point(23.2415, -106.4090, Srid::WGS84);

        $seccionEncontrada = Seccion::query()
            ->where('municipio_id', $mazatlan->id)
            ->whereContains('geom', $puntoDentroDeLaSeccion2756)
            ->first();

        $this->assertNotNull($seccionEncontrada);
        $this->assertSame(2756, $seccionEncontrada->numero);
    }
}
