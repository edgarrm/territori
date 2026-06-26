<?php

namespace Tests\Feature\Tenancy;

use App\Models\Membership;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ResolveTenantMiddlewareTest extends TestCase
{
    use RefreshDatabase;

    private ?int $entidadId = null;

    private function municipioId(): int
    {
        $this->entidadId ??= DB::table('entidades')->insertGetId(['clave' => 25, 'nombre' => 'Sinaloa', 'created_at' => now(), 'updated_at' => now()]);

        return DB::table('municipios')->insertGetId(['entidad_id' => $this->entidadId, 'clave' => random_int(1, 999), 'nombre' => 'Mazatlán', 'created_at' => now(), 'updated_at' => now()]);
    }

    public function test_login_con_una_sola_membership_entra_directo_al_dashboard(): void
    {
        $tenant = Tenant::create(['nombre' => 'T', 'municipio_id' => $this->municipioId()]);
        $user = User::factory()->create();
        Membership::create(['tenant_id' => $tenant->id, 'user_id' => $user->id, 'rol' => 'coordinador']);

        $response = $this->post(route('login.store'), [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $response->assertRedirect(route('dashboard', absolute: false));
        $this->assertEquals($tenant->id, session('tenant_id'));
    }

    public function test_login_con_varias_memberships_muestra_selector(): void
    {
        $tenantA = Tenant::create(['nombre' => 'A', 'municipio_id' => $this->municipioId()]);
        $tenantB = Tenant::create(['nombre' => 'B', 'municipio_id' => $this->municipioId()]);
        $user = User::factory()->create();
        Membership::create(['tenant_id' => $tenantA->id, 'user_id' => $user->id, 'rol' => 'coordinador']);
        Membership::create(['tenant_id' => $tenantB->id, 'user_id' => $user->id, 'rol' => 'brigadista']);

        $response = $this->post(route('login.store'), [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $response->assertRedirect(route('campanas.seleccionar'));
    }

    public function test_login_sin_memberships_muestra_mensaje(): void
    {
        $user = User::factory()->create();

        $response = $this->post(route('login.store'), [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $response->assertRedirect(route('campanas.sin-membership'));
    }

    public function test_selector_fija_tenant_en_sesion(): void
    {
        $tenantA = Tenant::create(['nombre' => 'A', 'municipio_id' => $this->municipioId()]);
        $tenantB = Tenant::create(['nombre' => 'B', 'municipio_id' => $this->municipioId()]);
        $user = User::factory()->create();
        Membership::create(['tenant_id' => $tenantA->id, 'user_id' => $user->id, 'rol' => 'coordinador']);
        Membership::create(['tenant_id' => $tenantB->id, 'user_id' => $user->id, 'rol' => 'brigadista']);

        $this->actingAs($user);
        $response = $this->post(route('campanas.seleccionar.store'), ['tenant_id' => $tenantB->id]);

        $response->assertRedirect(route('dashboard'));
        $this->assertEquals($tenantB->id, session('tenant_id'));
    }

    public function test_resolucion_por_subdominio_fija_el_tenant(): void
    {
        config(['app.tenant_domain' => 'territori.test']);

        $tenant = Tenant::create(['nombre' => 'A', 'municipio_id' => $this->municipioId(), 'subdominio' => 'campania-a']);
        $user = User::factory()->create();
        Membership::create(['tenant_id' => $tenant->id, 'user_id' => $user->id, 'rol' => 'coordinador']);

        $response = $this->actingAs($user)
            ->get('http://campania-a.territori.test/dashboard');

        $response->assertOk();
    }
}
