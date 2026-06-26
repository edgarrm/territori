<?php

namespace Tests\Feature\Tenancy;

use App\Actions\Campana\CrearCampana;
use App\Actions\Campana\InvitarMiembro;
use App\Models\Membership;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CampanaTest extends TestCase
{
    use RefreshDatabase;

    private function municipioId(int $clave = 12): int
    {
        $entidadId = DB::table('entidades')->insertGetId(['clave' => 25, 'nombre' => 'Sinaloa', 'created_at' => now(), 'updated_at' => now()]);

        return DB::table('municipios')->insertGetId(['entidad_id' => $entidadId, 'clave' => $clave, 'nombre' => 'Mazatlán', 'created_at' => now(), 'updated_at' => now()]);
    }

    public function test_crear_campana_no_duplica_secciones_solo_referencia_municipio(): void
    {
        $municipioId = $this->municipioId();

        $tenant = (new CrearCampana)->handle([
            'nombre' => 'Campaña Mazatlán',
            'municipio_id' => $municipioId,
            'subdominio' => 'mazatlan2026',
        ]);

        $this->assertSame($municipioId, $tenant->municipio_id);
        $this->assertSame(0, DB::table('secciones')->count());
    }

    public function test_subdominio_repetido_falla(): void
    {
        $municipioId = $this->municipioId();
        (new CrearCampana)->handle(['nombre' => 'A', 'municipio_id' => $municipioId, 'subdominio' => 'repetido']);

        $this->expectException(QueryException::class);
        (new CrearCampana)->handle(['nombre' => 'B', 'municipio_id' => $municipioId, 'subdominio' => 'repetido']);
    }

    public function test_invitar_miembro_crea_user_y_membership(): void
    {
        $tenant = Tenant::create(['nombre' => 'T', 'municipio_id' => $this->municipioId()]);

        $membership = (new InvitarMiembro)->handle($tenant, [
            'email' => 'nuevo@example.com',
            'rol' => 'brigadista',
            'meta_diaria' => 15,
        ]);

        $this->assertSame('brigadista', $membership->rol);
        $this->assertSame(15, $membership->meta_diaria);
        $this->assertNotNull(User::where('email', 'nuevo@example.com')->first());
    }

    public function test_invitar_brigadista_sobre_el_limite_lanza_excepcion(): void
    {
        $tenant = Tenant::create(['nombre' => 'T', 'municipio_id' => $this->municipioId(), 'limite_brigadistas' => 1]);
        (new InvitarMiembro)->handle($tenant, ['email' => 'uno@example.com', 'rol' => 'brigadista']);

        $this->expectException(\RuntimeException::class);
        (new InvitarMiembro)->handle($tenant, ['email' => 'dos@example.com', 'rol' => 'brigadista']);
    }

    public function test_activar_y_desactivar_membership_registra_timestamps(): void
    {
        $tenant = Tenant::create(['nombre' => 'T', 'municipio_id' => $this->municipioId()]);
        $user = User::factory()->create();
        $membership = Membership::create(['tenant_id' => $tenant->id, 'user_id' => $user->id, 'rol' => 'coordinador', 'activo' => false]);

        $membership->activar();
        $this->assertTrue($membership->activo);
        $this->assertNotNull($membership->activado_en);

        $membership->desactivar();
        $this->assertFalse($membership->activo);
        $this->assertNotNull($membership->desactivado_en);
    }
}
