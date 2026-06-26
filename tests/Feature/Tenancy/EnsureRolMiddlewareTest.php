<?php

namespace Tests\Feature\Tenancy;

use App\Models\Membership;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class EnsureRolMiddlewareTest extends TestCase
{
    use RefreshDatabase;

    private function municipioId(): int
    {
        $entidadId = DB::table('entidades')->insertGetId(['clave' => 25, 'nombre' => 'Sinaloa', 'created_at' => now(), 'updated_at' => now()]);

        return DB::table('municipios')->insertGetId(['entidad_id' => $entidadId, 'clave' => 12, 'nombre' => 'Mazatlán', 'created_at' => now(), 'updated_at' => now()]);
    }

    public function test_brigadista_no_puede_acceder_a_ruta_de_admin(): void
    {
        $tenant = Tenant::create(['nombre' => 'T', 'municipio_id' => $this->municipioId()]);
        $user = User::factory()->create();
        Membership::create(['tenant_id' => $tenant->id, 'user_id' => $user->id, 'rol' => 'brigadista']);

        $response = $this->actingAs($user)->withSession(['tenant_id' => $tenant->id])
            ->get(route('campanas.crear'));

        $response->assertForbidden();
    }

    public function test_admin_puede_acceder_a_ruta_de_admin(): void
    {
        $tenant = Tenant::create(['nombre' => 'T', 'municipio_id' => $this->municipioId()]);
        $user = User::factory()->create();
        Membership::create(['tenant_id' => $tenant->id, 'user_id' => $user->id, 'rol' => 'admin']);

        $response = $this->actingAs($user)->withSession(['tenant_id' => $tenant->id])
            ->get(route('campanas.crear'));

        $response->assertOk();
    }
}
