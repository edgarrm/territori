<?php

namespace Tests\Feature\Tenancy;

use App\Models\Municipio;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CatalogoNoEscopadoTest extends TestCase
{
    use RefreshDatabase;

    public function test_municipio_no_se_filtra_por_tenant(): void
    {
        $entidadId = DB::table('entidades')->insertGetId(['clave' => 25, 'nombre' => 'Sinaloa', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('municipios')->insert(['entidad_id' => $entidadId, 'clave' => 12, 'nombre' => 'Mazatlán', 'created_at' => now(), 'updated_at' => now()]);

        TenantContext::clear();

        $this->assertSame(1, Municipio::count());
    }
}
