<?php

namespace Tests\Feature\Campana;

use App\Models\Membership;
use App\Models\Municipio;
use App\Models\Partido;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class CampanaSettingsTest extends TestCase
{
    use RefreshDatabase;

    private function tenantConMiembro(string $rol): array
    {
        $municipio = Municipio::factory()->create();
        $tenant = Tenant::factory()->create(['municipio_id' => $municipio->id]);
        $user = User::factory()->create();
        Membership::factory()->create(['tenant_id' => $tenant->id, 'user_id' => $user->id, 'rol' => $rol]);

        return [$tenant, $user];
    }

    private function actuandoEn(User $user, Tenant $tenant): static
    {
        return $this->actingAs($user)->withSession(['tenant_id' => $tenant->id]);
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'partido_id' => null,
            'umbral_ganada_franca' => 30,
            'umbral_alfa' => 1000,
            'umbral_beta' => 500,
            'indicadores' => [
                'competitividad' => true,
                'tipo_seccion' => true,
                'indice_neutral' => true,
                'oportunidad' => true,
            ],
        ], $overrides);
    }

    public function test_admin_ve_defaults_sin_settings_guardados(): void
    {
        [$tenant, $admin] = $this->tenantConMiembro('admin');

        $this->actuandoEn($admin, $tenant)->get('/settings/campana')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('settings/Campana')
                ->where('partidoId', null)
                ->where('configuracion.umbral_ganada_franca', 30)
                ->where('configuracion.umbral_alfa', 1000)
                ->where('configuracion.umbral_beta', 500)
                ->where('configuracion.indicadores.competitividad', true)
            );
    }

    public function test_admin_guarda_partido_y_umbrales(): void
    {
        [$tenant, $admin] = $this->tenantConMiembro('admin');
        $morena = Partido::factory()->create(['siglas' => 'MORENA']);

        $this->actuandoEn($admin, $tenant)
            ->patch('/settings/campana', $this->payload([
                'partido_id' => $morena->id,
                'umbral_ganada_franca' => 50,
            ]))
            ->assertRedirect();

        $tenant->refresh();
        $this->assertSame($morena->id, $tenant->partido_id);
        $this->assertSame(50, $tenant->configuracion()['umbral_ganada_franca']);
        $this->assertSame(1000, $tenant->configuracion()['umbral_alfa']);
    }

    public function test_configuracion_efectiva_se_comparte_como_prop_inertia(): void
    {
        [$tenant, $admin] = $this->tenantConMiembro('admin');
        $morena = Partido::factory()->create(['siglas' => 'MORENA']);
        $tenant->update(['partido_id' => $morena->id, 'settings' => ['umbral_ganada_franca' => 50]]);

        $this->actuandoEn($admin, $tenant)->get('/settings/campana')
            ->assertInertia(fn (Assert $page) => $page
                ->where('campana.partido.siglas', 'MORENA')
                ->where('campana.umbral_ganada_franca', 50)
                ->where('campana.umbral_alfa', 1000)
                ->where('campana.indicadores.competitividad', true)
            );
    }

    public function test_coordinador_no_accede(): void
    {
        [$tenant, $coordinador] = $this->tenantConMiembro('coordinador');

        $this->actuandoEn($coordinador, $tenant)->get('/settings/campana')->assertForbidden();
        $this->actuandoEn($coordinador, $tenant)->patch('/settings/campana', $this->payload())->assertForbidden();
    }

    public function test_brigadista_no_accede(): void
    {
        [$tenant, $brigadista] = $this->tenantConMiembro('brigadista');

        $this->actuandoEn($brigadista, $tenant)->get('/settings/campana')->assertForbidden();
        $this->actuandoEn($brigadista, $tenant)->patch('/settings/campana', $this->payload())->assertForbidden();
    }

    public function test_umbral_beta_mayor_o_igual_a_alfa_falla_validacion(): void
    {
        [$tenant, $admin] = $this->tenantConMiembro('admin');

        $this->actuandoEn($admin, $tenant)
            ->from('/settings/campana')
            ->patch('/settings/campana', $this->payload(['umbral_alfa' => 500, 'umbral_beta' => 500]))
            ->assertRedirect('/settings/campana')
            ->assertSessionHasErrors('umbral_alfa');
    }

    public function test_umbrales_no_positivos_fallan_validacion(): void
    {
        [$tenant, $admin] = $this->tenantConMiembro('admin');

        $this->actuandoEn($admin, $tenant)
            ->from('/settings/campana')
            ->patch('/settings/campana', $this->payload(['umbral_ganada_franca' => 0]))
            ->assertRedirect('/settings/campana')
            ->assertSessionHasErrors('umbral_ganada_franca');
    }
}
