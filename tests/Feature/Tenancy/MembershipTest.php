<?php

namespace Tests\Feature\Tenancy;

use App\Models\Membership;
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
