<?php

namespace Tests\Feature\Tenancy;

use App\Models\Membership;
use App\Models\Seccion;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class MembershipTest extends TestCase
{
    use RefreshDatabase;

    private function municipioId(): int
    {
        $entidadId = DB::table('entidades')->insertGetId(['clave' => random_int(1, 32000), 'nombre' => 'Test', 'created_at' => now(), 'updated_at' => now()]);

        return DB::table('municipios')->insertGetId(['entidad_id' => $entidadId, 'clave' => 1, 'nombre' => 'Test', 'created_at' => now(), 'updated_at' => now()]);
    }

    private function tenant(): Tenant
    {
        return Tenant::create(['nombre' => 'Campaña '.uniqid(), 'municipio_id' => $this->municipioId()]);
    }

    public function test_una_persona_puede_tener_rol_distinto_en_dos_campanas(): void
    {
        $user = User::factory()->create();
        $tenantA = $this->tenant();
        $tenantB = $this->tenant();

        Membership::create(['tenant_id' => $tenantA->id, 'user_id' => $user->id, 'rol' => 'coordinador']);
        Membership::create(['tenant_id' => $tenantB->id, 'user_id' => $user->id, 'rol' => 'brigadista', 'meta_diaria' => 20]);

        $this->assertSame('coordinador', $user->membershipEn($tenantA)->rol);
        $this->assertSame('brigadista', $user->membershipEn($tenantB)->rol);
        $this->assertSame(20, $user->membershipEn($tenantB)->meta_diaria);
    }

    public function test_unicidad_de_membership_por_tenant_y_usuario(): void
    {
        $user = User::factory()->create();
        $tenant = $this->tenant();

        Membership::create(['tenant_id' => $tenant->id, 'user_id' => $user->id, 'rol' => 'coordinador']);

        $this->expectException(QueryException::class);
        Membership::create(['tenant_id' => $tenant->id, 'user_id' => $user->id, 'rol' => 'brigadista']);
    }

    public function test_roles_que_puede_asignar_segun_su_propio_rol(): void
    {
        $admin = (new Membership(['rol' => 'admin']));
        $coordinador = (new Membership(['rol' => 'coordinador']));
        $brigadista = (new Membership(['rol' => 'brigadista']));
        $enlace = (new Membership(['rol' => 'enlace']));
        $anfitrion = (new Membership(['rol' => 'anfitrion']));

        // El admin puede crear cualquier rol.
        $this->assertEqualsCanonicalizing(
            ['admin', 'coordinador', 'brigadista', 'enlace', 'anfitrion'],
            $admin->rolesQuePuedeAsignar(),
        );

        // El coordinador solo puede crear brigadistas, enlaces y anfitriones.
        $this->assertEqualsCanonicalizing(
            ['brigadista', 'enlace', 'anfitrion'],
            $coordinador->rolesQuePuedeAsignar(),
        );

        // Brigadista, enlace y anfitrión no pueden crear a nadie.
        $this->assertSame([], $brigadista->rolesQuePuedeAsignar());
        $this->assertSame([], $enlace->rolesQuePuedeAsignar());
        $this->assertSame([], $anfitrion->rolesQuePuedeAsignar());

        $this->assertTrue($admin->puedeAsignarRol('coordinador'));
        $this->assertFalse($coordinador->puedeAsignarRol('admin'));
        $this->assertFalse($coordinador->puedeAsignarRol('coordinador'));
        $this->assertTrue($coordinador->puedeAsignarRol('enlace'));
    }

    public function test_brigadista_solo_puede_capturar_en_sus_zonas_asignadas(): void
    {
        $tenant = $this->tenant();
        $seccionAsignada = Seccion::create(['municipio_id' => $tenant->municipio_id, 'numero' => 1]);
        $seccionAjena = Seccion::create(['municipio_id' => $tenant->municipio_id, 'numero' => 2]);

        $user = User::factory()->create();
        $brigadista = Membership::create(['tenant_id' => $tenant->id, 'user_id' => $user->id, 'rol' => 'brigadista']);
        $brigadista->secciones()->attach($seccionAsignada->id, ['tenant_id' => $tenant->id]);

        $this->assertTrue($brigadista->puedeCapturarEnSeccion($seccionAsignada->id));
        $this->assertFalse($brigadista->puedeCapturarEnSeccion($seccionAjena->id));
    }

    public function test_anfitrion_solo_puede_capturar_en_sus_zonas_asignadas(): void
    {
        $tenant = $this->tenant();
        $seccionAsignada = Seccion::create(['municipio_id' => $tenant->municipio_id, 'numero' => 1]);
        $seccionAjena = Seccion::create(['municipio_id' => $tenant->municipio_id, 'numero' => 2]);

        $user = User::factory()->create();
        $anfitrion = Membership::create(['tenant_id' => $tenant->id, 'user_id' => $user->id, 'rol' => 'anfitrion']);
        $anfitrion->secciones()->attach($seccionAsignada->id, ['tenant_id' => $tenant->id]);

        $this->assertTrue($anfitrion->puedeCapturarEnSeccion($seccionAsignada->id));
        $this->assertFalse($anfitrion->puedeCapturarEnSeccion($seccionAjena->id));
    }

    public function test_gestion_puede_capturar_en_cualquier_seccion(): void
    {
        $tenant = $this->tenant();
        $seccion = Seccion::create(['municipio_id' => $tenant->municipio_id, 'numero' => 1]);

        $coordUser = User::factory()->create();
        $coord = Membership::create(['tenant_id' => $tenant->id, 'user_id' => $coordUser->id, 'rol' => 'coordinador']);

        // Gestión no depende de zonas: siempre puede.
        $this->assertTrue($coord->puedeCapturarEnSeccion($seccion->id));
    }

    public function test_activar_brigadista_por_encima_del_limite_se_bloquea(): void
    {
        $tenant = $this->tenant();
        $tenant->update(['limite_brigadistas' => 1]);

        $u1 = User::factory()->create();

        $this->assertTrue($tenant->puedeActivarBrigadista());

        Membership::create(['tenant_id' => $tenant->id, 'user_id' => $u1->id, 'rol' => 'brigadista', 'activo' => true]);

        $this->assertFalse($tenant->puedeActivarBrigadista());
    }
}
