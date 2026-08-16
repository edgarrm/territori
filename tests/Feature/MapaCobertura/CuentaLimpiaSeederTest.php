<?php

namespace Tests\Feature\MapaCobertura;

use App\Models\Municipio;
use App\Models\Seccion;
use App\Models\Tenant;
use Database\Seeders\CuentaLimpiaSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CuentaLimpiaSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_fija_meta_a_la_mitad_de_la_lista_nominal_y_cobertura_en_cero(): void
    {
        $municipio = Municipio::factory()->create();
        $seccionMil = Seccion::factory()->create(['municipio_id' => $municipio->id, 'numero' => 1, 'lista_nominal' => 1000]);
        $seccionDosMil = Seccion::factory()->create(['municipio_id' => $municipio->id, 'numero' => 2, 'lista_nominal' => 2000]);
        // Lista nominal impar: la mitad se redondea (1001 / 2 = 500.5 -> 501).
        $seccionImpar = Seccion::factory()->create(['municipio_id' => $municipio->id, 'numero' => 4, 'lista_nominal' => 1001]);
        // Sección sin padrón: NO debe recibir meta (evitar meta 0 que mata la cobertura).
        $seccionSinPadron = Seccion::factory()->create(['municipio_id' => $municipio->id, 'numero' => 3, 'lista_nominal' => null]);

        (new CuentaLimpiaSeeder)->run();

        $tenant = Tenant::query()->where('subdominio', 'mazatlan')->first();
        $this->assertNotNull($tenant);
        $this->assertSame($municipio->id, $tenant->municipio_id);

        // Meta = mitad de la lista nominal, fuente lista_nominal_pct al 50%.
        $metaMil = DB::table('metas_seccion')->where('tenant_id', $tenant->id)->where('seccion_id', $seccionMil->id)->first();
        $this->assertSame(500, (int) $metaMil->meta_capturas);
        $this->assertSame('lista_nominal_pct', $metaMil->fuente_meta);
        $this->assertEquals(50, (int) $metaMil->pct_lista_nominal);

        $metaDosMil = DB::table('metas_seccion')->where('tenant_id', $tenant->id)->where('seccion_id', $seccionDosMil->id)->first();
        $this->assertSame(1000, (int) $metaDosMil->meta_capturas);

        $metaImpar = DB::table('metas_seccion')->where('tenant_id', $tenant->id)->where('seccion_id', $seccionImpar->id)->first();
        $this->assertSame(501, (int) $metaImpar->meta_capturas);

        // La sección sin padrón queda sin meta (no se inserta meta 0).
        $this->assertNull(
            DB::table('metas_seccion')->where('tenant_id', $tenant->id)->where('seccion_id', $seccionSinPadron->id)->first(),
        );
        $this->assertSame(3, DB::table('metas_seccion')->where('tenant_id', $tenant->id)->count());
        $this->assertSame(0, DB::table('metas_seccion')->where('tenant_id', $tenant->id)->where('meta_capturas', 0)->count());

        // Cobertura arranca limpia: capturados 0, cobertura 0. Meta refleja el padrón.
        $cobertura = DB::table('cobertura_seccion')->where('tenant_id', $tenant->id)->where('seccion_id', $seccionMil->id)->first();
        $this->assertSame(0, (int) $cobertura->capturados);
        $this->assertSame(500, (int) $cobertura->meta);
        $this->assertEquals(0, (float) $cobertura->cobertura);

        // Cuenta limpia: sin electores.
        $this->assertSame(0, DB::table('electores')->count());
    }
}
