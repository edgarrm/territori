<?php

namespace Tests\Feature;

use App\Models\CoberturaSeccion;
use App\Models\Elector;
use App\Models\Evento;
use App\Models\Membership;
use App\Models\Seccion;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{0: Tenant, 1: User, 2: Membership}
     */
    private function setupCampana(string $rol): array
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create();
        $membership = Membership::factory()->for($tenant)->for($user)->create(['rol' => $rol, 'activo' => true]);
        TenantContext::set($tenant);

        return [$tenant, $user, $membership];
    }

    public function test_guests_are_redirected_to_the_login_page()
    {
        $response = $this->get(route('dashboard'));
        $response->assertRedirect(route('login'));
    }

    public function test_authenticated_users_with_an_active_campaign_can_visit_the_dashboard()
    {
        [$tenant, $user] = $this->setupCampana('admin');

        $response = $this->actingAs($user)
            ->withSession(['tenant_id' => $tenant->id])
            ->get(route('dashboard'));

        $response->assertOk();
    }

    public function test_admin_dashboard_muestra_kpis_de_campana(): void
    {
        [$tenant, $user] = $this->setupCampana('admin');

        $s1 = Seccion::factory()->create();
        $s2 = Seccion::factory()->create();
        CoberturaSeccion::factory()->create(['tenant_id' => $tenant->id, 'seccion_id' => $s1->id, 'capturados' => 30, 'meta' => 100]);
        CoberturaSeccion::factory()->create(['tenant_id' => $tenant->id, 'seccion_id' => $s2->id, 'capturados' => 70, 'meta' => 100]);

        Membership::factory()->for($tenant)->create(['rol' => 'brigadista', 'activo' => true]);
        Membership::factory()->for($tenant)->create(['rol' => 'brigadista', 'activo' => true]);

        $this->actingAs($user)
            ->withSession(['tenant_id' => $tenant->id])
            ->get(route('dashboard'))
            ->assertInertia(fn (Assert $page) => $page
                ->component('Dashboard')
                ->where('rol', 'admin')
                ->where('kpis', fn ($kpis) => collect($kpis)->firstWhere('clave', 'capturados')['valor'] === 100)
                ->where('kpis', fn ($kpis) => collect($kpis)->firstWhere('clave', 'meta')['valor'] === 200)
                ->where('kpis', fn ($kpis) => collect($kpis)->firstWhere('clave', 'avance')['valor'] === 50)
                ->where('kpis', fn ($kpis) => collect($kpis)->firstWhere('clave', 'brigadistas')['valor'] === 2)
            );
    }

    public function test_brigadista_dashboard_muestra_sus_propios_numeros(): void
    {
        [$tenant, $user, $membership] = $this->setupCampana('brigadista');

        $otro = Membership::factory()->for($tenant)->create(['rol' => 'brigadista', 'activo' => true]);

        Elector::factory()->count(3)->create(['tenant_id' => $tenant->id, 'membership_id' => $membership->id]);
        Elector::factory()->count(2)->create(['tenant_id' => $tenant->id, 'membership_id' => $otro->id]);

        $seccion = Seccion::factory()->create();
        $membership->secciones()->attach($seccion->id, ['tenant_id' => $tenant->id]);

        $this->actingAs($user)
            ->withSession(['tenant_id' => $tenant->id])
            ->get(route('dashboard'))
            ->assertInertia(fn (Assert $page) => $page
                ->component('Dashboard')
                ->where('rol', 'brigadista')
                ->where('kpis', fn ($kpis) => collect($kpis)->firstWhere('clave', 'mis_capturados')['valor'] === 3)
                ->where('kpis', fn ($kpis) => collect($kpis)->firstWhere('clave', 'mis_secciones')['valor'] === 1)
            );
    }

    public function test_dashboard_lista_proximos_eventos(): void
    {
        [$tenant, $user] = $this->setupCampana('admin');

        Evento::factory()->create(['tenant_id' => $tenant->id, 'fecha' => now()->subDays(3), 'nombre' => 'Evento Pasado']);
        Evento::factory()->create(['tenant_id' => $tenant->id, 'fecha' => now()->addDays(3), 'nombre' => 'Evento Futuro']);

        $this->actingAs($user)
            ->withSession(['tenant_id' => $tenant->id])
            ->get(route('dashboard'))
            ->assertInertia(fn (Assert $page) => $page
                ->component('Dashboard')
                ->where('eventosProximos', fn ($eventos) => collect($eventos)->pluck('nombre')->contains('Evento Futuro')
                    && ! collect($eventos)->pluck('nombre')->contains('Evento Pasado'))
            );
    }
}
